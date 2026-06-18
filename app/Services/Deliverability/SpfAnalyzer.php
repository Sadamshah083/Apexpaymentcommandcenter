<?php

namespace App\Services\Deliverability;

use SPFLib\Check\Environment;
use SPFLib\Check\Result;
use SPFLib\Checker;
use SPFLib\Decoder;
use SPFLib\OnlineSemanticValidator;
use SPFLib\SemanticValidator;

class SpfAnalyzer
{
    public function analyze(string $domain, ?string $sendingIp = null): array
    {
        $records = @dns_get_record($domain, DNS_TXT);
        $spfRecord = null;

        foreach ($records ?: [] as $record) {
            $txt = $record['txt'] ?? '';
            if (str_starts_with($txt, 'v=spf1')) {
                $spfRecord = $txt;
                break;
            }
        }

        if (! $spfRecord) {
            return [
                'status' => 'fail',
                'score' => 0,
                'message' => 'No SPF record found',
                'record' => null,
                'recommendation' => "Add SPF TXT record: v=spf1 include:_spf.google.com ~all (adjust for your mail provider)",
            ];
        }

        try {
            $decoder = new Decoder;
            $decoded = $decoder->getRecordFromTXT($spfRecord);

            if (! $decoded) {
                return [
                    'status' => 'fail',
                    'score' => 2,
                    'message' => 'SPF record found but could not be parsed',
                    'record' => $spfRecord,
                    'recommendation' => 'Fix SPF record syntax per RFC 7208',
                ];
            }

            $validator = new SemanticValidator;
            $issues = $validator->validate($decoded);

            $onlineIssues = [];
            try {
                $onlineValidator = new OnlineSemanticValidator;
                $onlineIssues = $onlineValidator->validate($decoded);
            } catch (\Throwable) {
                // DNS lookups may fail locally
            }

            $ipPass = null;
            if ($sendingIp) {
                try {
                    $checker = new Checker;
                    $environment = new Environment($sendingIp, $domain, "verify@{$domain}");
                    $checkResult = $checker->check($environment);
                    $ipPass = $checkResult->getCode() === Result::CODE_PASS;
                } catch (\Throwable) {
                    $ipPass = null;
                }
            }

            $hasIssues = count($issues) > 0 || count($onlineIssues) > 0;
            $status = $hasIssues ? 'warn' : ($ipPass === false ? 'warn' : 'pass');
            $score = $hasIssues ? 5 : ($ipPass === false ? 6 : 10);

            return [
                'status' => $status,
                'score' => $score,
                'message' => $hasIssues ? 'SPF record has syntax issues' : 'SPF record found and valid',
                'record' => $spfRecord,
                'issues' => array_map(fn ($i) => (string) $i, $issues),
                'ip_authorized' => $ipPass,
                'recommendation' => $hasIssues
                    ? 'Fix SPF syntax issues and ensure all includes resolve'
                    : ($ipPass === false ? 'Your sending IP is not authorized in SPF record' : null),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'warn',
                'score' => 4,
                'message' => 'SPF record found but could not be fully validated: '.$e->getMessage(),
                'record' => $spfRecord,
                'recommendation' => 'Review SPF record syntax per RFC 7208',
            ];
        }
    }
}
