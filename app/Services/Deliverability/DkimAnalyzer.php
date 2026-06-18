<?php

namespace App\Services\Deliverability;

class DkimAnalyzer
{
    public function analyze(string $domain, ?string $selector = 'default'): array
    {
        $selector = $selector ?: 'default';
        $host = "{$selector}._domainkey.{$domain}";
        $records = @dns_get_record($host, DNS_TXT);

        if (empty($records)) {
            $selectors = ['default', 'google', 'selector1', 'selector2', 'k1', 'mail'];
            foreach ($selectors as $trySelector) {
                if ($trySelector === $selector) {
                    continue;
                }
                $tryHost = "{$trySelector}._domainkey.{$domain}";
                $tryRecords = @dns_get_record($tryHost, DNS_TXT);
                if (! empty($tryRecords)) {
                    $selector = $trySelector;
                    $host = $tryHost;
                    $records = $tryRecords;
                    break;
                }
            }
        }

        if (empty($records)) {
            return [
                'status' => 'fail',
                'score' => 0,
                'message' => 'No DKIM record found',
                'selector' => $selector,
                'record' => null,
                'recommendation' => "Add DKIM TXT record at {$selector}._domainkey.{$domain} from your mail provider",
            ];
        }

        $txt = '';
        foreach ($records as $record) {
            $txt .= $record['txt'] ?? '';
        }

        $hasPublicKey = str_contains($txt, 'p=') && ! str_contains($txt, 'p=;');
        $status = $hasPublicKey ? 'pass' : 'warn';

        return [
            'status' => $status,
            'score' => $hasPublicKey ? 10 : 3,
            'message' => $hasPublicKey ? "DKIM record found (selector: {$selector})" : 'DKIM record found but public key may be revoked',
            'selector' => $selector,
            'record' => $txt,
            'recommendation' => $hasPublicKey ? null : 'Generate a new DKIM key pair and publish the public key',
        ];
    }
}
