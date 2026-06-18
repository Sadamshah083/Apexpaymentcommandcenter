<?php

namespace Tests\Unit\Jobs;

use App\Jobs\ProcessLeadJob;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowLead;
use App\Models\Workspace;
use App\Services\Workflow\WorkflowExtractor;
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

    public function test_lead_is_assigned_before_extraction_starts(): void
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
        ]);

        $lead = WorkflowLead::create([
            'workflow_id' => $workflow->id,
            'status' => 'pending',
            'row_number' => 1,
            'business_name' => 'Test Business',
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
            ]);

        $this->app->instance(WorkflowExtractor::class, $extractor);

        $job = new ProcessLeadJob($lead->id);
        $job->handle(
            $extractor,
            app(\App\Services\Workflow\WorkflowLeadDistributor::class),
            app(\App\Services\Workspace\WorkspaceSyncService::class),
        );

        $this->assertTrue($assignedBeforeExtract);
        $this->assertSame($agent->id, $lead->fresh()->assigned_user_id);
        $this->assertSame('completed', $lead->fresh()->status);
    }
}
