<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowLead;
use App\Models\Workspace;
use App\Services\SalesOps\LeadActivityService;
use App\Services\SalesOps\LeadReactivationService;
use App\Support\SalesOps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesOpsPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_tier_advances_with_contact_attempts(): void
    {
        $this->assertSame('tier_1', SalesOps::tierFromAttempts(0));
        $this->assertSame('tier_2', SalesOps::tierFromAttempts(2));
        $this->assertSame('tier_3', SalesOps::tierFromAttempts(7));
        $this->assertSame('tier_4', SalesOps::tierFromAttempts(11));
    }

    public function test_logging_dial_increments_attempts_and_stage(): void
    {
        $admin = User::factory()->create();
        $sdr = User::factory()->create(['current_workspace_id' => null]);
        $workspace = Workspace::create(['name' => 'Apex', 'admin_id' => $admin->id]);
        $workspace->users()->attach($sdr->id, ['role' => 'sdr', 'status' => 'active', 'joined_at' => now()]);
        $sdr->update(['current_workspace_id' => $workspace->id]);

        $workflow = Workflow::create(['workspace_id' => $workspace->id, 'name' => 'Pipe', 'status' => 'completed']);
        $lead = WorkflowLead::create([
            'workflow_id' => $workflow->id,
            'assigned_user_id' => $sdr->id,
            'status' => 'completed',
            'stage' => 'new_lead',
            'row_number' => 1,
            'business_name' => 'Merchant Co',
        ]);

        app(LeadActivityService::class)->log($lead, $sdr, 'dial', 'no_answer');

        $lead->refresh();
        $this->assertSame(1, $lead->contact_attempts);
        $this->assertSame('tier_2', $lead->tier);
        $this->assertSame('attempted_contact', $lead->stage);
    }

    public function test_reactivation_resets_lead_to_tier_one(): void
    {
        $admin = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Apex', 'admin_id' => $admin->id]);
        $workflow = Workflow::create(['workspace_id' => $workspace->id, 'name' => 'Pipe', 'status' => 'completed']);

        $lead = WorkflowLead::create([
            'workflow_id' => $workflow->id,
            'status' => 'completed',
            'stage' => 'closed_lost',
            'contact_attempts' => 8,
            'tier' => 'tier_3',
            'row_number' => 1,
            'business_name' => 'Old Merchant',
        ]);

        app(LeadReactivationService::class)->enroll($lead, 'lost_opportunity');

        $lead->refresh();
        $this->assertSame('new_lead', $lead->stage);
        $this->assertSame('tier_1', $lead->tier);
        $this->assertSame('lost_opportunity', $lead->reactivation_source);
    }

    public function test_admin_can_view_sales_ops_command_center(): void
    {
        $admin = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Apex', 'admin_id' => $admin->id]);
        $workspace->users()->attach($admin->id, ['role' => 'admin', 'status' => 'active', 'joined_at' => now()]);
        $admin->update(['current_workspace_id' => $workspace->id]);

        $this->actingAs($admin)
            ->get(route('admin.sales-ops.index'))
            ->assertOk()
            ->assertSee('Apex One Sales Operations');
    }

    public function test_sdr_can_view_performance_dashboard(): void
    {
        $admin = User::factory()->create();
        $sdr = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Apex', 'admin_id' => $admin->id]);
        $workspace->users()->attach($sdr->id, ['role' => 'sdr', 'status' => 'active', 'joined_at' => now()]);
        $sdr->update(['current_workspace_id' => $workspace->id]);

        $this->actingAs($sdr)
            ->get(route('portal.performance'))
            ->assertOk()
            ->assertSee('SDR Daily Performance');
    }
}
