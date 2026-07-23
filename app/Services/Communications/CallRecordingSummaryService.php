<?php

namespace App\Services\Communications;

use App\Models\CommunicationCallLog;
use App\Models\User;
use App\Models\Workspace;
use App\Services\BusinessResearch\OpenRouterClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class CallRecordingSummaryService
{
    public function __construct(
        protected OpenRouterClient $openRouter,
    ) {}

    /**
     * Cache-first call summary. AI runs only on miss / regenerate, with locks + rate limits
     * so repeated opens stay instant and cannot stampede the server.
     *
     * @return array<string, mixed>
     */
    public function summarize(
        Workspace $workspace,
        CommunicationCallLog $log,
        bool $force = false,
        bool $allowAi = true,
    ): array {
        if ((int) $log->workspace_id !== (int) $workspace->id) {
            throw new RuntimeException('Call log does not belong to this workspace.');
        }

        $cacheKey = $this->cacheKey((int) $log->id);

        if (! $force) {
            $fromAppCache = Cache::get($cacheKey);
            if (is_array($fromAppCache) && filled($fromAppCache['summary'] ?? null)
                && ! $this->looksLikePromptLeak((string) $fromAppCache['summary'])) {
                return [...$fromAppCache, 'cached' => true, 'ai_enhanced' => true];
            }

            $meta = is_array($log->meta) ? $log->meta : [];
            $cached = trim((string) ($meta['ai_call_summary'] ?? ''));
            if ($cached !== '' && ! $this->looksLikePromptLeak($cached)) {
                $payload = $this->payload($log, $cached, true, $meta['ai_call_summary_model'] ?? null, true);
                Cache::put($cacheKey, $payload, now()->addDays(14));

                return $payload;
            }
        }

        if (! $allowAi) {
            throw new RuntimeException('AI call summary is not available yet. Open Summary to generate it.');
        }

        $rateKey = 'call_summary:rate:w'.(int) $workspace->id;
        $rateCount = (int) Cache::get($rateKey, 0);
        $rateLimit = max(3, (int) config('openrouter.call_summary_rate_per_minute', 8));
        if ($rateCount >= $rateLimit) {
            // Soft fallback — never knock the app over when AI is busy.
            $fallback = $this->fallbackSummary($log);
            $payload = $this->payload($log, $fallback, false, 'rate-limited-fallback', true);
            $payload['rate_limited'] = true;

            return $payload;
        }

        $lock = Cache::lock('call_summary:lock:'.$log->id, 25);
        if (! $lock->get()) {
            // Another request is already generating — wait briefly for its cache write.
            for ($i = 0; $i < 8; $i++) {
                usleep(250_000);
                $fromAppCache = Cache::get($cacheKey);
                if (is_array($fromAppCache) && filled($fromAppCache['summary'] ?? null)) {
                    return [...$fromAppCache, 'cached' => true, 'ai_enhanced' => true];
                }
                $log->refresh();
                $meta = is_array($log->meta) ? $log->meta : [];
                $cached = trim((string) ($meta['ai_call_summary'] ?? ''));
                if ($cached !== '' && ! $this->looksLikePromptLeak($cached)) {
                    return $this->payload($log, $cached, true, $meta['ai_call_summary_model'] ?? null, true);
                }
            }

            return $this->payload($log, $this->fallbackSummary($log), false, 'lock-wait-fallback', true);
        }

        try {
            // Re-check after lock (another worker may have finished).
            if (! $force) {
                $fromAppCache = Cache::get($cacheKey);
                if (is_array($fromAppCache) && filled($fromAppCache['summary'] ?? null)) {
                    return [...$fromAppCache, 'cached' => true, 'ai_enhanced' => true];
                }
                $log->refresh();
                $meta = is_array($log->meta) ? $log->meta : [];
                $cached = trim((string) ($meta['ai_call_summary'] ?? ''));
                if ($cached !== '' && ! $this->looksLikePromptLeak($cached)) {
                    $payload = $this->payload($log, $cached, true, $meta['ai_call_summary_model'] ?? null, true);
                    Cache::put($cacheKey, $payload, now()->addDays(14));

                    return $payload;
                }
            }

            Cache::put($rateKey, $rateCount + 1, now()->addMinute());

            $context = $this->buildContext($log);
            $system = <<<'PROMPT'
Write one polished US-English call summary paragraph for a CRM popup.

Style example:
The **agent**, **Rebecca**, **called Mavis White** to **discuss** the **Senior Safety Health Program**. She **declined** the **offer** and the **call ended respectfully**.

Rules: 3–4 sentences only. Bold key facts with **asterisks**. No titles, labels, or invented details. For no-answer/machine, say no real conversation happened.
PROMPT;

            $user = "Facts:\n{$context}\n\nSummary:";

            $summary = '';
            $modelUsed = null;

            try {
                $result = $this->openRouter->chatForCallSummary($system, $user, 200);
                $summary = $this->normalizeSummary((string) ($result['content'] ?? ''));
                $modelUsed = isset($result['model']) ? (string) $result['model'] : null;
            } catch (\Throwable $e) {
                report($e);
                $summary = '';
            }

            if ($summary === '' || $this->looksLikePromptLeak($summary)) {
                $summary = $this->fallbackSummary($log);
                $modelUsed = $modelUsed ?: 'local-fallback';
            }

            $meta = is_array($log->meta) ? $log->meta : [];
            $meta['ai_call_summary'] = $summary;
            $meta['ai_call_summary_at'] = now()->toIso8601String();
            $meta['ai_call_summary_model'] = $modelUsed;
            $log->meta = $meta;
            $log->save();

            if ($log->workspace_id) {
                AgentStatusReportService::forgetCachesForWorkspace((int) $log->workspace_id);
            }

            $payload = $this->payload($log, $summary, false, $modelUsed, true);
            Cache::put($cacheKey, $payload, now()->addDays(14));

            return $payload;
        } finally {
            optional($lock)->release();
        }
    }

    public function cacheKey(int $callLogId): string
    {
        return 'call_summary:v1:log:'.$callLogId;
    }

    public function forgetCachedSummary(int $callLogId): void
    {
        Cache::forget($this->cacheKey($callLogId));
    }

    public function isValidCachedSummary(?string $summary): bool
    {
        $summary = trim((string) $summary);

        return $summary !== '' && ! $this->looksLikePromptLeak($summary);
    }

    /**
     * Build a downloadable document with disposition, caller, and destination number.
     */
    public function downloadDocument(array $payload): string
    {
        $summary = preg_replace('/\*\*(.+?)\*\*/u', '$1', (string) ($payload['summary'] ?? '')) ?? '';
        $lines = [
            'AI call recording summary',
            str_repeat('=', 48),
            'Caller (agent): '.(string) ($payload['agent'] ?? '—'),
            'From number: '.(string) ($payload['from_phone'] ?? '—'),
            'Called number: '.(string) ($payload['to_phone'] ?? $payload['phone'] ?? '—'),
            'Disposition: '.(string) ($payload['status'] ?? '—'),
            'Duration: '.(string) ($payload['duration_label'] ?? '—'),
            'When: '.(string) ($payload['when'] ?? '—'),
            '',
            'AI summary',
            str_repeat('-', 48),
            trim($summary),
            '',
        ];

        return implode("\n", $lines);
    }

    /**
     * @param  Collection<int, CommunicationCallLog>  $logs
     * @return list<array<string, mixed>>
     */
    public function bulkExportRows(Collection $logs): array
    {
        $rows = [];
        foreach ($logs as $log) {
            $meta = is_array($log->meta) ? $log->meta : [];
            $summary = trim((string) ($meta['ai_call_summary'] ?? ''));
            if ($summary === '') {
                $summary = $this->fallbackSummary($log);
            }
            $payload = $this->payload($log, $summary, $summary !== '' && filled($meta['ai_call_summary'] ?? null), null, filled($meta['ai_call_summary'] ?? null));
            $rows[] = $payload;
        }

        return $rows;
    }

    public function findLog(Workspace $workspace, ?string $callLogRef, ?string $callUuid): ?CommunicationCallLog
    {
        $ref = trim((string) $callLogRef);
        if (str_starts_with($ref, 'local:')) {
            $id = (int) substr($ref, 6);
            if ($id > 0) {
                return CommunicationCallLog::query()
                    ->where('workspace_id', $workspace->id)
                    ->where('id', $id)
                    ->with('user:id,name,email')
                    ->first();
            }
        }

        $uuid = trim((string) $callUuid);
        if ($uuid !== '') {
            return CommunicationCallLog::query()
                ->where('workspace_id', $workspace->id)
                ->where('morpheus_call_uuid', $uuid)
                ->with('user:id,name,email')
                ->orderByDesc('id')
                ->first();
        }

        if (ctype_digit($ref) && (int) $ref > 0) {
            return CommunicationCallLog::query()
                ->where('workspace_id', $workspace->id)
                ->where('id', (int) $ref)
                ->with('user:id,name,email')
                ->first();
        }

        return null;
    }

    public function assertViewerCanAccess(User $viewer, CommunicationCallLog $log, string $tier, array $allowedAgentIds): void
    {
        if (in_array($tier, ['admin', 'supervisor', 'qa'], true)) {
            return;
        }

        $uid = (int) ($log->user_id ?? 0);
        if ($uid <= 0 || ! in_array($uid, array_map('intval', $allowedAgentIds), true)) {
            abort(403, 'You cannot view this call summary.');
        }
    }

    protected function buildContext(CommunicationCallLog $log): string
    {
        $meta = is_array($log->meta) ? $log->meta : [];
        $inCall = trim((string) ($meta['in_call_notes'] ?? ''));
        $note = trim((string) ($log->note ?? ''));
        $agent = (string) ($log->user?->name ?? 'Unknown agent');
        $to = (string) ($log->to_phone ?: 'unknown');
        $from = (string) ($log->from_phone ?: 'unknown');
        $status = trim((string) ($log->disposition ?: $log->status ?: 'Unknown')) ?: 'Unknown';
        $sec = (int) ($log->duration_sec ?? 0);
        $direction = trim((string) ($log->direction ?? 'outbound')) ?: 'outbound';
        $when = optional($log->created_at)->timezone(config('app.timezone'))?->format('M j, Y g:i A') ?? 'unknown time';

        $lines = [
            "Caller (agent): {$agent}",
            "From number: {$from}",
            "Called number: {$to}",
            "Direction: {$direction}",
            "When: {$when}",
            "Disposition: {$status}",
            'Duration (seconds): '.$sec.' ('.$this->formatDuration($sec).')',
            'Has recording file: '.(! empty($log->recording_file_id) ? 'yes' : 'no'),
        ];

        if ($note !== '') {
            $lines[] = "Agent disposition note: {$note}";
        }
        if ($inCall !== '') {
            $lines[] = "In-call notes: {$inCall}";
        }

        foreach (['lead_name', 'contact_name', 'campaign', 'campaign_name'] as $key) {
            $val = trim((string) ($meta[$key] ?? ''));
            if ($val !== '') {
                $lines[] = ucfirst(str_replace('_', ' ', $key)).": {$val}";
            }
        }

        return implode("\n", $lines);
    }

    protected function normalizeSummary(string $raw): string
    {
        $text = trim($raw);
        $text = preg_replace('/^```(?:\w+)?\s*/u', '', $text) ?? $text;
        $text = preg_replace('/\s*```$/u', '', $text) ?? $text;
        $text = preg_replace('/^(?:ai\s+)?(?:call\s+)?(?:recording\s+)?summary\s*:\s*/iu', '', $text) ?? $text;
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        $text = trim($text);

        // Keep a single paragraph.
        $text = preg_replace("/\s*\n+\s*/", ' ', $text) ?? $text;
        $text = preg_replace('/\s{2,}/', ' ', $text) ?? $text;

        $sentences = preg_split('/(?<=[.!?])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($sentences) > 4) {
            $text = implode(' ', array_slice($sentences, 0, 4));
        }

        return trim($text);
    }

    protected function looksLikePromptLeak(string $text): bool
    {
        $hay = mb_strtolower($text);

        foreach ([
            'write exactly',
            'hard rules',
            'double asterisks',
            'return only',
            'output style',
            'facts for this call',
            'caller (agent):',
            'from number:',
            'called number:',
            'has recording file',
            'you write polished',
            'you are a trained',
            'call recording context',
            'write the ai call',
            'write the call summary',
        ] as $needle) {
            if (str_contains($hay, $needle)) {
                return true;
            }
        }

        // Raw labeled dumps from context.
        if (preg_match('/\b(disposition|direction|duration \(seconds\))\s*:/i', $text)) {
            return true;
        }

        return mb_strlen($text) > 900;
    }

    protected function fallbackSummary(CommunicationCallLog $log): string
    {
        $agent = (string) ($log->user?->name ?: 'the agent');
        $to = (string) ($log->to_phone ?: 'the contact');
        $status = trim((string) ($log->disposition ?: $log->status ?: 'Unknown')) ?: 'Unknown';
        $sec = (int) ($log->duration_sec ?? 0);
        $when = optional($log->created_at)->timezone(config('app.timezone'))?->format('M j, Y g:i A') ?? 'the selected time';
        $note = trim((string) ($log->note ?? ''));
        $inCall = trim((string) (data_get($log->meta, 'in_call_notes') ?? ''));
        $direction = strtolower(trim((string) ($log->direction ?? 'outbound'))) ?: 'outbound';
        $statusLower = mb_strtolower($status);

        $lead = "The **agent**, **{$agent}**, placed an **{$direction}** call to **{$to}** on **{$when}**.";

        if (str_contains($statusLower, 'no answer') || str_contains($statusLower, 'not available')) {
            $mid = "The line rang for **{$this->formatDuration($sec)}** with **no meaningful conversation**.";
        } elseif (str_contains($statusLower, 'answering machine') || str_contains($statusLower, 'voicemail')) {
            $mid = "The call reached an **answering machine / voicemail** after **{$this->formatDuration($sec)}**.";
        } elseif (str_contains($statusLower, 'hung up')) {
            $mid = "The conversation lasted **{$this->formatDuration($sec)}** before the **contact hung up**.";
        } elseif (str_contains($statusLower, 'not interested')) {
            $mid = "After **{$this->formatDuration($sec)}**, the contact was **not interested** in continuing.";
        } elseif (str_contains($statusLower, 'appointment') || str_contains($statusLower, 'call back') || str_contains($statusLower, 'callback')) {
            $mid = "The call lasted **{$this->formatDuration($sec)}** and ended with a **follow-up arrangement**.";
        } else {
            $mid = "The call lasted **{$this->formatDuration($sec)}**.";
        }

        $end = "Disposition was logged as **{$status}**.";
        $extraNote = $note !== '' ? $note : $inCall;
        if ($extraNote !== '') {
            $end .= " Agent notes: **{$extraNote}**.";
        }

        return trim("{$lead} {$mid} {$end}");
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(
        CommunicationCallLog $log,
        string $summary,
        bool $cached,
        ?string $model,
        bool $aiEnhanced,
    ): array {
        $sec = (int) ($log->duration_sec ?? 0);
        $to = (string) ($log->to_phone ?: '—');
        $from = (string) ($log->from_phone ?: '—');
        $when = optional($log->created_at)->timezone(config('app.timezone'))?->format('M j, Y g:i A') ?? '—';

        $base = [
            'summary' => $summary,
            'summary_html' => $this->toHighlightedHtml($summary),
            'phone' => $to !== '—' ? $to : $from,
            'from_phone' => $from,
            'to_phone' => $to,
            'duration_label' => $this->formatDuration($sec),
            'duration_sec' => $sec,
            'agent' => (string) ($log->user?->name ?? '—'),
            'status' => trim((string) ($log->disposition ?: $log->status ?: 'Unknown')) ?: 'Unknown',
            'when' => $when,
            'cached' => $cached,
            'ai_enhanced' => $aiEnhanced,
            'model' => $model,
            'instant' => ! $aiEnhanced && ! $cached,
        ];
        $base['download_text'] = $this->downloadDocument($base);

        return $base;
    }

    protected function toHighlightedHtml(string $summary): string
    {
        $escaped = e($summary);
        $html = preg_replace(
            '/\*\*(.+?)\*\*/u',
            '<span class="ai-call-summary-em">$1</span>',
            $escaped
        ) ?? $escaped;

        return nl2br($html, false);
    }

    protected function formatDuration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;

        return sprintf('%d:%02d:%02d', $h, $m, $s);
    }
}
