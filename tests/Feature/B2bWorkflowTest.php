<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use App\Models\Workflow;
use App\Models\WorkflowLead;
use App\Services\Workflow\WorkflowLeadDistributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class B2bWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_creation_and_membership(): void
    {
        $admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
        ]);

        $workspace = Workspace::create([
            'name' => 'Sales Team A',
            'admin_id' => $admin->id,
        ]);

        $workspace->users()->attach($admin->id, ['role' => 'admin']);

        $this->assertEquals('Sales Team A', $workspace->name);
        $this->assertEquals($admin->id, $workspace->admin_id);
        $this->assertTrue($workspace->users->contains($admin));
    }

    public function test_lead_round_robin_distribution(): void
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'password']);
        $user1 = User::create(['name' => 'Marketer 1', 'email' => 'm1@example.com', 'password' => 'password']);
        $user2 = User::create(['name' => 'Marketer 2', 'email' => 'm2@example.com', 'password' => 'password']);

        $workspace = Workspace::create(['name' => 'Sales', 'admin_id' => $admin->id]);
        $workspace->users()->attach([
            $user1->id => ['role' => 'marketer', 'status' => 'active', 'joined_at' => now()],
            $user2->id => ['role' => 'marketer', 'status' => 'active', 'joined_at' => now()],
        ]);

        $workflow = Workflow::create([
            'workspace_id' => $workspace->id,
            'name' => 'Test Pipeline',
            'status' => 'extracting'
        ]);

        $leads = collect();
        for ($i = 1; $i <= 5; $i++) {
            $leads->push(WorkflowLead::create([
                'workflow_id' => $workflow->id,
                'business_name' => "Business {$i}",
                'row_number' => $i
            ]));
        }

        $distributor = new WorkflowLeadDistributor();
        $distributor->distribute($workspace, $leads);

        // Verify assignment (leads 1, 3, 5 to user1, leads 2, 4 to user2)
        $this->assertEquals($user1->id, $leads[0]->fresh()->assigned_user_id);
        $this->assertEquals($user2->id, $leads[1]->fresh()->assigned_user_id);
        $this->assertEquals($user1->id, $leads[2]->fresh()->assigned_user_id);
        $this->assertEquals($user2->id, $leads[3]->fresh()->assigned_user_id);
        $this->assertEquals($user1->id, $leads[4]->fresh()->assigned_user_id);
    }
}
