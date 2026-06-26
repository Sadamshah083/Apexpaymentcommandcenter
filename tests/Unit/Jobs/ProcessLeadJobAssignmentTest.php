<?php

namespace Tests\Unit\Jobs;

use App\Jobs\ProcessLeadJob;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowLead;
use App\Models\Workspace;
use App\Services\Workflow\WorkflowExtractor;
use App\Services\Workflow\WorkflowLeadAutoVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ProcessLeadJobAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_enriched_lead_queues_for_manual_verification_without_pre_assignment(): void
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
            'distribution_users' => [$agent->id],
            'distribution_cursor' => 0,
            'verification_toggles' => ['email' => '1', 'domain' => '1'],
        ]);

        $lead = WorkflowLead::create([
            'workflow_id' => $workflow->id,
            'status' => 'pending',
            'row_number' => 1,
            'business_name' => 'Test Business',
            'direct_email' => 'owner@example.com',
            'website' => 'example.com',
        ]);

        $assignedBeforeExtract = false;

        $extractor = Mockery::mock(WorkflowExtractor::class);
        $extractor->shouldReceive('extract')
            ->once()
            ->with(Mockery::on(function (WorkflowLead $model) use ($lead, &$assignedBeforeExtract) {
                $assignedBeforeExtract = (bool) $model->assigned_user_id;

                return $model->id === $lead->id;
            }), null)
            ->andReturn([
                'status' => 'completed',
                'owner_name' => 'Owner',
                'direct_email' => 'owner@example.com',
            ]);

        $autoVerification = Mockery::mock(WorkflowLeadAutoVerificationService::class);
        $autoVerification->shouldReceive('run')
            ->once()
            ->andReturn(['ran_at' => now()->toIso8601String(), 'email' => ['syntax' => ['valid' => true]]]);

        $this->app->instance(WorkflowExtractor::class, $extractor);
        $this->app->instance(WorkflowLeadAutoVerificationService::class, $autoVerification);

        $job = new ProcessLeadJob($lead->id);
        $job->handle(
            $extractor,
            $autoVerification,
            app(\App\Services\Workspace\WorkspaceSyncService::class),
        );

        $lead->refresh();
        $workflow->refresh();

        $this->assertFalse($assignedBeforeExtract);
        $this->assertNull($lead->assigned_user_id);
        $this->assertSame('pending_verification', $lead->status);
        $this->assertSame('pending', $lead->verification_status);
        $this->assertSame(0, $workflow->processed_leads);
    }
}
