<?php

namespace App\Jobs;

use App\Models\DeliverabilityTest;
use App\Services\Deliverability\DeliverabilityAnalyzer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunDomainAuthCheckJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $testId,
    ) {}

    public function handle(DeliverabilityAnalyzer $analyzer): void
    {
        $test = DeliverabilityTest::findOrFail($this->testId);
        $test->update(['status' => 'processing']);

        $result = $analyzer->analyze(
            $test->domain,
            $test->sending_ip,
            $test->dkim_selector,
        );

        $test->update([
            'spf_result' => $result['spf_result'],
            'dkim_result' => $result['dkim_result'],
            'dmarc_result' => $result['dmarc_result'],
            'mx_result' => $result['mx_result'],
            'ptr_result' => $result['ptr_result'],
            'dnsbl_result' => $result['dnsbl_result'],
            'overall_score' => $result['overall_score'],
            'recommendations' => $result['recommendations'],
            'status' => 'completed',
        ]);
    }
}
