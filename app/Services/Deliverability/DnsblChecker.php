<?php

namespace App\Services\Deliverability;

class DnsblChecker
{
    protected array $lists = [
        'zen.spamhaus.org' => 'Spamhaus ZEN',
        'bl.spamcop.net' => 'SpamCop',
        'dnsbl.sorbs.net' => 'SORBS',
    ];

    public function check(?string $ip): array
    {
        if (! $ip || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            return [
                'status' => 'skip',
                'score' => null,
                'message' => 'No valid sending IP provided',
                'listings' => [],
            ];
        }

        $reversed = implode('.', array_reverse(explode('.', $ip)));
        $listings = [];

        foreach ($this->lists as $list => $name) {
            $query = "{$reversed}.{$list}";
            $result = @dns_get_record($query, DNS_A);

            if (! empty($result)) {
                $listings[] = [
                    'list' => $name,
                    'host' => $list,
                    'response' => $result[0]['ip'] ?? 'listed',
                ];
            }
        }

        if (count($listings) > 0) {
            return [
                'status' => 'fail',
                'score' => 0,
                'message' => 'IP is listed on '.count($listings).' blocklist(s)',
                'listings' => $listings,
                'recommendation' => 'Request delisting and investigate spam complaints before sending',
            ];
        }

        return [
            'status' => 'pass',
            'score' => 10,
            'message' => 'IP not found on checked blocklists',
            'listings' => [],
        ];
    }
}
