<?php

namespace App\Services\Verification;

class MxRecordChecker
{
    public function check(string $domain): array
    {
        $sleepMs = config('email_checker.verification.dns_sleep_ms', 100);
        if ($sleepMs > 0) {
            usleep($sleepMs * 1000);
        }

        $mxRecords = @dns_get_record($domain, DNS_MX);

        if (! empty($mxRecords)) {
            usort($mxRecords, fn ($a, $b) => ($a['pri'] ?? 0) <=> ($b['pri'] ?? 0));

            return [
                'passed' => true,
                'status' => 'pass',
                'message' => 'Domain has MX records',
                'metadata' => [
                    'mx_records' => array_map(fn ($r) => [
                        'host' => $r['target'] ?? '',
                        'priority' => $r['pri'] ?? 0,
                    ], $mxRecords),
                ],
            ];
        }

        $aRecords = @dns_get_record($domain, DNS_A);

        if (! empty($aRecords)) {
            return [
                'passed' => true,
                'status' => 'pass',
                'message' => 'Domain has A record (implicit mail delivery)',
                'metadata' => [
                    'a_records' => array_column($aRecords, 'ip'),
                ],
            ];
        }

        return [
            'passed' => false,
            'status' => 'invalid',
            'message' => 'No MX or A records found for domain',
            'metadata' => [],
        ];
    }
}
