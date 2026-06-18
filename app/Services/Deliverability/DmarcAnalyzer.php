<?php

namespace App\Services\Deliverability;

use TomCan\Dmarc\DmarcParser;
use TomCan\PublicSuffixList\PSLIcann;

class DmarcAnalyzer
{
    public function analyze(string $domain): array
    {
        try {
            $parser = new DmarcParser(new PSLIcann);
            $record = $parser->query($domain);

            if (! $record) {
                return [
                    'status' => 'fail',
                    'score' => 0,
                    'message' => 'No DMARC record found',
                    'record' => null,
                    'policy' => null,
                    'recommendation' => "Add DMARC at _dmarc.{$domain}: v=DMARC1; p=none; rua=mailto:dmarc@{$domain}",
                ];
            }

            $policy = $record['p'] ?? 'none';
            $status = match ($policy) {
                'reject' => 'pass',
                'quarantine' => 'pass',
                'none' => 'warn',
                default => 'warn',
            };
            $score = match ($policy) {
                'reject' => 10,
                'quarantine' => 9,
                'none' => 6,
                default => 5,
            };

            $recordString = collect($record)->map(fn ($v, $k) => "{$k}={$v}")->implode('; ');

            return [
                'status' => $status,
                'score' => $score,
                'message' => "DMARC policy: {$policy}",
                'record' => $recordString,
                'policy' => $policy,
                'pct' => $record['pct'] ?? '100',
                'rua' => $record['rua'] ?? null,
                'recommendation' => $policy === 'none'
                    ? 'Start with p=none, monitor reports, then move to quarantine/reject'
                    : null,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'fail',
                'score' => 0,
                'message' => 'DMARC lookup failed: '.$e->getMessage(),
                'record' => null,
                'recommendation' => "Add DMARC record at _dmarc.{$domain}",
            ];
        }
    }
}
