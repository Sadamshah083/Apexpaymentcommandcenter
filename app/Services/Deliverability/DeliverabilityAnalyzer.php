<?php

namespace App\Services\Deliverability;

class DeliverabilityAnalyzer
{
    public function __construct(
        protected SpfAnalyzer $spfAnalyzer,
        protected DkimAnalyzer $dkimAnalyzer,
        protected DmarcAnalyzer $dmarcAnalyzer,
        protected DnsblChecker $dnsblChecker,
    ) {}

    public function analyze(string $domain, ?string $sendingIp = null, ?string $dkimSelector = null): array
    {
        $mxRecords = @dns_get_record($domain, DNS_MX);
        $mxResult = [
            'status' => ! empty($mxRecords) ? 'pass' : 'warn',
            'score' => ! empty($mxRecords) ? 10 : 5,
            'message' => ! empty($mxRecords) ? 'MX records configured' : 'No MX records (may be send-only domain)',
            'records' => array_map(fn ($r) => [
                'host' => $r['target'] ?? '',
                'priority' => $r['pri'] ?? 0,
            ], $mxRecords ?: []),
        ];

        $ptrResult = ['status' => 'skip', 'score' => null, 'message' => 'No sending IP provided', 'hostname' => null];
        if ($sendingIp && filter_var($sendingIp, FILTER_VALIDATE_IP)) {
            $hostname = gethostbyaddr($sendingIp);
            $ptrValid = $hostname && $hostname !== $sendingIp;
            $ptrResult = [
                'status' => $ptrValid ? 'pass' : 'warn',
                'score' => $ptrValid ? 10 : 4,
                'message' => $ptrValid ? "PTR record: {$hostname}" : 'No valid PTR/reverse DNS',
                'hostname' => $ptrValid ? $hostname : null,
                'recommendation' => $ptrValid ? null : 'Configure reverse DNS (PTR) to match your sending hostname',
            ];
        }

        $spf = $this->spfAnalyzer->analyze($domain, $sendingIp);
        $dkim = $this->dkimAnalyzer->analyze($domain, $dkimSelector);
        $dmarc = $this->dmarcAnalyzer->analyze($domain);
        $dnsbl = $this->dnsblChecker->check($sendingIp);

        $scores = array_filter([
            $spf['score'] ?? null,
            $dkim['score'] ?? null,
            $dmarc['score'] ?? null,
            $mxResult['score'] ?? null,
            $ptrResult['score'] ?? null,
            $dnsbl['score'] ?? null,
        ], fn ($s) => $s !== null);

        $overallScore = count($scores) > 0 ? round(array_sum($scores) / count($scores), 2) : 0;

        $recommendations = array_values(array_filter([
            $spf['recommendation'] ?? null,
            $dkim['recommendation'] ?? null,
            $dmarc['recommendation'] ?? null,
            $ptrResult['recommendation'] ?? null,
            $dnsbl['recommendation'] ?? null,
        ]));

        return [
            'domain' => $domain,
            'sending_ip' => $sendingIp,
            'spf_result' => $spf,
            'dkim_result' => $dkim,
            'dmarc_result' => $dmarc,
            'mx_result' => $mxResult,
            'ptr_result' => $ptrResult,
            'dnsbl_result' => $dnsbl,
            'overall_score' => $overallScore,
            'recommendations' => $recommendations,
            'mail_tester_score' => round($overallScore, 1),
        ];
    }
}
