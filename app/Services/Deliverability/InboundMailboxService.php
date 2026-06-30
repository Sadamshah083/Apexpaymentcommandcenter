<?php

namespace App\Services\Deliverability;

use App\Models\ContentAnalysis;
use App\Models\InboundTestInbox;
use App\Services\Content\ContentRuleEngine;
use Illuminate\Support\Facades\Log;

class InboundMailboxService
{
    public function __construct(
        protected ContentRuleEngine $contentEngine,
    ) {}

    public function poll(): int
    {
        $this->expireWaitingInboxes();

        $host = config('email_checker.inbound.imap_host');
        if (! $host) {
            return 0;
        }

        if (! function_exists('imap_open')) {
            Log::warning('Inbound mailbox polling skipped: PHP IMAP extension is not installed.');

            return 0;
        }

        $port = (int) config('email_checker.inbound.imap_port', 993);
        $username = config('email_checker.inbound.imap_username');
        $password = config('email_checker.inbound.imap_password');

        if (! $username || ! $password) {
            Log::warning('Inbound mailbox polling skipped: IMAP credentials are not configured.');

            return 0;
        }

        $mailbox = sprintf('{%s:%d/imap/ssl/novalidate-cert}INBOX', $host, $port);
        $connection = @imap_open($mailbox, $username, $password);

        if (! $connection) {
            Log::warning('Inbound mailbox IMAP connection failed.', ['error' => imap_last_error()]);

            return 0;
        }

        $processed = 0;

        try {
            $waiting = InboundTestInbox::query()
                ->where('status', 'waiting')
                ->where('expires_at', '>', now())
                ->get();

            foreach ($waiting as $inbox) {
                if ($this->processInbox($connection, $inbox)) {
                    $processed++;
                }
            }
        } finally {
            imap_close($connection);
        }

        return $processed;
    }

    public function expireWaitingInboxes(): void
    {
        InboundTestInbox::query()
            ->where('status', 'waiting')
            ->where('expires_at', '<', now())
            ->update(['status' => 'expired']);
    }

    protected function processInbox($connection, InboundTestInbox $inbox): bool
    {
        $address = $inbox->email_address;
        $search = @imap_search($connection, 'TO "'.$address.'" UNSEEN');

        if (! is_array($search) || $search === []) {
            $search = @imap_search($connection, 'TO "'.$address.'"');
        }

        if (! is_array($search) || $search === []) {
            return false;
        }

        $messageNumber = (int) $search[0];
        $rawHeader = (string) imap_fetchheader($connection, $messageNumber);
        $rawBody = (string) imap_body($connection, $messageNumber, FT_PEEK);
        $rawMessage = $rawHeader."\r\n\r\n".$rawBody;

        $parsedHeaders = $this->parseHeaders($rawHeader);
        $authResults = $this->extractAuthResults($rawHeader);
        $subject = $parsedHeaders['subject'] ?? '(no subject)';
        $htmlBody = $this->extractHtmlBody($rawBody) ?: $rawBody;
        $textBody = $this->extractTextBody($rawBody) ?: strip_tags($htmlBody);

        $contentResult = $this->contentEngine->analyze($subject, $htmlBody, $textBody);

        $analysis = ContentAnalysis::create([
            'title' => 'Inbound test: '.$address,
            'subject' => $subject,
            'html_body' => $htmlBody,
            'text_body' => $textBody,
            'scores' => $contentResult['scores'],
            'highlights' => $contentResult['highlights'],
            'suggestions' => $contentResult['suggestions'],
            'spam_score' => $contentResult['spam_score'],
            'overall_score' => $contentResult['overall_score'],
        ]);

        $authScore = $this->scoreAuthResults($authResults);
        $contentScore = (float) ($contentResult['overall_score'] ?? 0);
        $overallScore = round(($authScore + $contentScore) / 2, 2);

        $inbox->update([
            'status' => 'analyzed',
            'parsed_headers' => $parsedHeaders,
            'auth_results' => $authResults,
            'raw_message' => $rawMessage,
            'content_analysis_id' => $analysis->id,
            'overall_score' => $overallScore,
        ]);

        imap_setflag_full($connection, (string) $messageNumber, '\\Seen');

        return true;
    }

    /**
     * @return array<string, string>
     */
    protected function parseHeaders(string $rawHeader): array
    {
        $headers = [];
        $current = null;

        foreach (preg_split('/\r\n|\n|\r/', $rawHeader) as $line) {
            if (preg_match('/^([\w-]+):\s*(.*)$/', $line, $matches)) {
                $current = strtolower($matches[1]);
                $headers[$current] = trim($matches[2]);
            } elseif ($current && (str_starts_with($line, ' ') || str_starts_with($line, "\t"))) {
                $headers[$current] .= ' '.trim($line);
            }
        }

        return $headers;
    }

    /**
     * @return array<string, mixed>
     */
    protected function extractAuthResults(string $rawHeader): array
    {
        $results = [];

        if (preg_match('/Authentication-Results:\s*(.+?)(?:\r?\n(?![\w-]+:)|\z)/is', $rawHeader, $matches)) {
            $block = $matches[1];
            foreach (['spf', 'dkim', 'dmarc'] as $mechanism) {
                if (preg_match('/\b'.$mechanism.'=(pass|fail|softfail|neutral|none|permerror|temperror)\b/i', $block, $m)) {
                    $results[$mechanism] = strtolower($m[1]);
                }
            }
            $results['raw'] = trim(preg_replace('/\s+/', ' ', $block) ?? $block);
        }

        if (preg_match('/Received-SPF:\s*(pass|fail|softfail|neutral|none)/i', $rawHeader, $matches)) {
            $results['spf_received'] = strtolower($matches[1]);
        }

        $results['list_unsubscribe'] = preg_match('/^List-Unsubscribe:/im', $rawHeader) === 1;

        return $results;
    }

    protected function scoreAuthResults(array $authResults): float
    {
        $score = 0.0;
        $count = 0;

        foreach (['spf', 'dkim', 'dmarc'] as $mechanism) {
            if (! isset($authResults[$mechanism])) {
                continue;
            }

            $count++;
            $score += match ($authResults[$mechanism]) {
                'pass' => 10.0,
                'neutral', 'none' => 6.0,
                'softfail', 'temperror' => 4.0,
                default => 2.0,
            };
        }

        if ($count === 0) {
            return 5.0;
        }

        return round($score / $count, 2);
    }

    protected function extractHtmlBody(string $rawBody): string
    {
        if (preg_match('/Content-Type:\s*text\/html[^\r\n]*\r?\n(?:[^\r\n]+\r?\n)*\r?\n(.*?)(?:\r?\n--|\z)/is', $rawBody, $matches)) {
            return trim($this->decodeBody($matches[1], $rawBody));
        }

        return '';
    }

    protected function extractTextBody(string $rawBody): string
    {
        if (preg_match('/Content-Type:\s*text\/plain[^\r\n]*\r?\n(?:[^\r\n]+\r?\n)*\r?\n(.*?)(?:\r?\n--|\z)/is', $rawBody, $matches)) {
            return trim($this->decodeBody($matches[1], $rawBody));
        }

        return '';
    }

    protected function decodeBody(string $body, string $context): string
    {
        if (preg_match('/Content-Transfer-Encoding:\s*base64/i', $context)) {
            $decoded = base64_decode(preg_replace('/\s+/', '', $body) ?? '', true);

            return is_string($decoded) ? $decoded : $body;
        }

        if (preg_match('/Content-Transfer-Encoding:\s*quoted-printable/i', $context)) {
            return quoted_printable_decode($body) ?: $body;
        }

        return $body;
    }
}
