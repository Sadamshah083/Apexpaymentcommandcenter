<?php

namespace Tests\Unit\Support;

use App\Models\Workflow;
use App\Support\PipelineProgress;
use PHPUnit\Framework\TestCase;

class PipelineProgressTest extends TestCase
{
    public function test_steps_mark_import_done_when_not_mapping(): void
    {
        $workflow = new Workflow([
            'status' => 'extracting',
            'processing_mode' => 'import_and_enrich',
            'total_leads' => 25,
            'enriched_leads' => 5,
            'failed_leads' => 2,
            'ingestion_complete' => true,
        ]);
        $workflow->setAttribute('pending_verification_count', 2);
        $workflow->setAttribute('assigned_leads_count', 0);

        $steps = PipelineProgress::steps($workflow);

        $this->assertTrue($steps[0]['done']);
        $this->assertSame('import', $steps[0]['key']);
        $this->assertTrue($steps[1]['active']);
        $this->assertStringContainsString('7 / 25', $steps[1]['detail']);
    }
}
