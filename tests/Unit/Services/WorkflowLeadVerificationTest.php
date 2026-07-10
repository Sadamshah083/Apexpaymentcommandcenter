<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowLead;
use App\Models\Workspace;
use App\Services\Workflow\WorkflowLeadVerificationService;
use App\Services\Workspace\WorkspaceSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class WorkflowLeadVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_approving_lead_assigns_agent_and_increments_processed_count(): void
    {
        $admin = User::factory()->create();
        $agent = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Acme', 'admin_id' => $admin->id]);
        $workspace->users()->attach($admin->id, ['role' => 'admin', 'status' => 'active', 'joined_at' => now()]);
        $workspace->users()->attach($agent->id, ['role' => 'marketer', 'status' => 'active', 'joined_at' => now()]);

        $workflow = Workflow::create([
            'workspace_id' => $workspace->id,
            'name' => 'Pipeline',
            'status' => 'extracting',
            'total_leads' => 1,
            'processed_leads' => 0,
            'distribution_users' => [$agent->id],
            'distribution_cursor' => 0,
        ]);

        $lead = WorkflowLead::create([
            'workflow_id' => $workflow->id,
            'status' => 'pending_verification',
            'verification_status' => 'pending',
            'row_number' => 1,
            'business_name' => 'Test Business',
            'owner_name' => 'Owner',
        ]);

        $sync = Mockery::mock(WorkspaceSyncService::class);
        $sync->shouldReceive('record')->once();

        $service = new WorkflowLeadVerificationService(
            app(\App\Services\Pipeline\SetterDistributionService::class),
            $sync,
        );

        $service->approve($lead, $admin);

        $lead->refresh();
        $workflow->refresh();

        $this->assertSame('completed', $lead->status);
        $this->assertSame('approved', $lead->verification_status);
        $this->assertSame($agent->id, $lead->assigned_user_id);
        $this->assertSame($admin->id, $lead->verified_by);
        $this->assertSame(1, $workflow->processed_leads);
    }

    public function test_rejecting_lead_marks_failed_and_increments_failed_count(): void
    {
        $admin = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Acme', 'admin_id' => $admin->id]);

        $workflow = Workflow::create([
            'workspace_id' => $workspace->id,
            'name' => 'Pipeline',
            'status' => 'extracting',
            'total_leads' => 1,
            'failed_leads' => 0,
        ]);

        $lead = WorkflowLead::create([
            'workflow_id' => $workflow->id,
            'status' => 'pending_verification',
            'verification_status' => 'pending',
            'row_number' => 1,
            'business_name' => 'Bad Lead',
        ]);

        $sync = Mockery::mock(WorkspaceSyncService::class);
        $sync->shouldReceive('record')->once();

        $service = new WorkflowLeadVerificationService(
            app(\App\Services\Pipeline\SetterDistributionService::class),
            $sync,
        );

        $service->reject($lead, $admin, 'Duplicate record');

        $lead->refresh();
        $workflow->refresh();

        $this->assertSame('failed', $lead->status);
        $this->assertSame('rejected', $lead->verification_status);
        $this->assertSame('Duplicate record', $lead->rejection_reason);
        $this->assertSame(1, $workflow->failed_leads);
    }
}
