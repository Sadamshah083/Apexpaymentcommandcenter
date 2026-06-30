<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowLead;
use App\Models\Workspace;
use App\Services\Workflow\WorkflowLeadService;
use App\Services\Workspace\WorkspaceSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class B2bWorkflowPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_portal_lead_route_resolves_workflow_lead(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Acme', 'admin_id' => $owner->id]);
        $workspace->users()->attach($owner->id, ['role' => 'admin', 'status' => 'active', 'joined_at' => now()]);

        $agent = User::factory()->create([
            'current_workspace_id' => $workspace->id,
        ]);
        $workspace->users()->attach($agent->id, ['role' => 'marketer', 'status' => 'active', 'joined_at' => now()]);

        $workflow = Workflow::create([
            'workspace_id' => $workspace->id,
            'name' => 'Pipeline',
            'status' => 'completed',
        ]);

        $lead = WorkflowLead::create([
            'workflow_id' => $workflow->id,
            'assigned_user_id' => $agent->id,
            'status' => 'completed',
            'row_number' => 1,
            'business_name' => 'Acme Plumbing',
        ]);

        $this->actingAs($agent)
            ->get(route('portal.leads.show', $lead))
            ->assertOk()
            ->assertSee('Acme Plumbing');
    }

    public function test_admin_can_pause_and_resume_pipeline(): void
    {
        Queue::fake();

        config(['openrouter.api_key' => 'test-key']);

        $admin = User::factory()->create(['current_workspace_id' => null]);
        $workspace = Workspace::create(['name' => 'Acme', 'admin_id' => $admin->id]);
        $workspace->users()->attach($admin->id, ['role' => 'admin', 'status' => 'active', 'joined_at' => now()]);
        $admin->update(['current_workspace_id' => $workspace->id]);

        $workflow = Workflow::create([
            'workspace_id' => $workspace->id,
            'name' => 'Pipeline',
            'status' => 'extracting',
            'total_leads' => 2,
            'file_path' => 'workflows/test.csv',
            'ingestion_complete' => true,
        ]);

        WorkflowLead::create([
            'workflow_id' => $workflow->id,
            'status' => 'imported',
            'row_number' => 1,
            'business_name' => 'Lead One',
        ]);

        WorkflowLead::create([
            'workflow_id' => $workflow->id,
            'status' => 'imported',
            'row_number' => 2,
            'business_name' => 'Lead Two',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.workflows.pause', $workflow))
            ->assertRedirect()
            ->assertSessionHas('success');

        $workflow->refresh();
        $this->assertEquals('paused', $workflow->status);

        $this->actingAs($admin)
            ->post(route('admin.workflows.resume', $workflow))
            ->assertRedirect()
            ->assertSessionHas('success');

        $workflow->refresh();
        $this->assertEquals('extracting', $workflow->status);

        Queue::assertPushed(\App\Jobs\ProcessLeadJob::class, 2);
    }

    public function test_deleting_lead_updates_workflow_counters(): void
    {
        $admin = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Acme', 'admin_id' => $admin->id]);
        $workflow = Workflow::create([
            'workspace_id' => $workspace->id,
            'name' => 'Pipeline',
            'status' => 'completed',
            'total_leads' => 2,
            'processed_leads' => 2,
            'failed_leads' => 0,
            'ingestion_complete' => true,
        ]);

        $lead = WorkflowLead::create([
            'workflow_id' => $workflow->id,
            'status' => 'completed',
            'row_number' => 1,
            'business_name' => 'Lead One',
        ]);

        WorkflowLead::create([
            'workflow_id' => $workflow->id,
            'status' => 'completed',
            'row_number' => 2,
            'business_name' => 'Lead Two',
        ]);

        $sync = Mockery::mock(WorkspaceSyncService::class);
        $sync->shouldReceive('record')->once();

        $service = new WorkflowLeadService(
            app(\App\Services\Verification\SyntaxValidator::class),
            app(\App\Services\Verification\MxRecordChecker::class),
            app(\App\Services\Verification\DisposableDomainChecker::class),
            app(\App\Services\Verification\SmtpVerifier::class),
            app(\App\Services\Content\ContentRuleEngine::class),
            app(\App\Services\Deliverability\DeliverabilityAnalyzer::class),
            $sync,
        );

        $service->delete($lead);

        $workflow->refresh();
        $this->assertEquals(1, $workflow->total_leads);
        $this->assertEquals(1, $workflow->processed_leads);
        $this->assertDatabaseMissing('workflow_leads', ['id' => $lead->id]);
    }
}
