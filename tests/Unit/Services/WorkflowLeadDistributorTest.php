<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Models\Workspace;
use App\Models\Workflow;
use App\Models\WorkflowLead;
use App\Services\Workflow\WorkflowLeadDistributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowLeadDistributorTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigns_leads_round_robin_equally_to_selected_agents(): void
    {
        $admin = User::factory()->create(['name' => 'Admin']);
        $agentA = User::factory()->create(['name' => 'Agent A']);
        $agentB = User::factory()->create(['name' => 'Agent B']);

        $workspace = Workspace::create([
            'name' => 'Test Workspace',
            'admin_id' => $admin->id,
        ]);

        $workspace->users()->attach($admin->id, ['role' => 'admin', 'status' => 'active', 'joined_at' => now()]);
        $workspace->users()->attach($agentA->id, ['role' => 'marketer', 'status' => 'active', 'joined_at' => now()]);
        $workspace->users()->attach($agentB->id, ['role' => 'marketer', 'status' => 'active', 'joined_at' => now()]);

        $workflow = Workflow::create([
            'workspace_id' => $workspace->id,
            'name' => 'Pipeline',
            'status' => 'extracting',
            'distribution_users' => [$agentA->id, $agentB->id],
            'distribution_cursor' => 0,
        ]);

        $distributor = app(WorkflowLeadDistributor::class);

        $leadOne = WorkflowLead::create([
            'workflow_id' => $workflow->id,
            'status' => 'pending',
            'row_number' => 1,
            'business_name' => 'Lead 1',
        ]);
        $leadTwo = WorkflowLead::create([
            'workflow_id' => $workflow->id,
            'status' => 'pending',
            'row_number' => 2,
            'business_name' => 'Lead 2',
        ]);
        $leadThree = WorkflowLead::create([
            'workflow_id' => $workflow->id,
            'status' => 'pending',
            'row_number' => 3,
            'business_name' => 'Lead 3',
        ]);

        $distributor->assignNext($workspace, $leadOne, $workflow);
        $distributor->assignNext($workspace, $leadTwo, $workflow->fresh());
        $distributor->assignNext($workspace, $leadThree, $workflow->fresh());

        $this->assertSame($agentA->id, $leadOne->fresh()->assigned_user_id);
        $this->assertSame($agentB->id, $leadTwo->fresh()->assigned_user_id);
        $this->assertSame($agentA->id, $leadThree->fresh()->assigned_user_id);
        $this->assertSame(3, $workflow->fresh()->distribution_cursor);
    }

    public function test_does_not_reassign_lead_that_already_has_an_agent(): void
    {
        $admin = User::factory()->create();
        $agent = User::factory()->create();

        $workspace = Workspace::create([
            'name' => 'Workspace',
            'admin_id' => $admin->id,
        ]);
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
            'business_name' => 'Lead',
            'assigned_user_id' => $agent->id,
        ]);

        $distributor = app(WorkflowLeadDistributor::class);
        $result = $distributor->assignNext($workspace, $lead, $workflow);

        $this->assertSame($agent->id, $result?->id);
        $this->assertSame(0, $workflow->fresh()->distribution_cursor);
    }
}
