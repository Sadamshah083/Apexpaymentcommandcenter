<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowLead;
use App\Models\Workspace;
use App\Services\Pipeline\LeadImportDedupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadImportDedupServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_discards_duplicate_in_same_batch(): void
    {
        $service = new LeadImportDedupService;
        $batch = [];

        $this->assertFalse($service->shouldDiscard(1, '+1 555-111-2222', $batch));
        $this->assertTrue($service->shouldDiscard(1, '(555) 111-2222', $batch));
    }

    public function test_discards_phone_already_in_workspace(): void
    {
        $admin = User::factory()->create();
        $workspace = Workspace::factory()->create(['admin_id' => $admin->id]);
        Workflow::create([
            'workspace_id' => $workspace->id,
            'name' => 'Import',
            'status' => 'completed',
        ]);
        $workflow = Workflow::query()->where('workspace_id', $workspace->id)->first();
        WorkflowLead::create([
            'workflow_id' => $workflow->id,
            'normalized_phone' => '15551112222',
            'business_name' => 'Existing',
            'row_number' => 1,
        ]);

        $service = new LeadImportDedupService;
        $batch = [];

        $this->assertTrue($service->shouldDiscard($workspace->id, '555-111-2222', $batch));
    }

    public function test_formats_phone_for_storage(): void
    {
        $service = new LeadImportDedupService;
        $result = $service->formatPhoneForStorage('5551234567');

        $this->assertSame('15551234567', $result['normalized_phone']);
        $this->assertSame('+1 (555) 123-4567', $result['input_phone']);
    }
}
