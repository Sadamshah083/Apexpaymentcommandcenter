<?php

namespace Tests\Feature;

use App\Models\LeadCampaign;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowLead;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminLeadShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_lead_detail_page(): void
    {
        $admin = User::factory()->create();
        $workspace = Workspace::create([
            'name' => 'Apex',
            'admin_id' => $admin->id,
        ]);

        $workspace->users()->attach($admin->id, [
            'role' => 'admin',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $admin->update(['current_workspace_id' => $workspace->id]);

        $campaign = LeadCampaign::create([
            'workspace_id' => $workspace->id,
            'name' => 'Summer Campaign',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $workflow = Workflow::create([
            'workspace_id' => $workspace->id,
            'campaign_id' => $campaign->id,
            'name' => 'Import Batch 1',
            'status' => 'completed',
            'original_filename' => 'import.csv',
        ]);

        $lead = WorkflowLead::create([
            'workflow_id' => $workflow->id,
            'campaign_id' => $campaign->id,
            'status' => 'completed',
            'pipeline_phase' => 'with_setter',
            'stage' => 'new_lead',
            'row_number' => 1,
            'business_name' => 'Merchant Co',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.leads.show', $lead))
            ->assertOk()
            ->assertSee('Merchant Co')
            ->assertSee('Summer Campaign');
    }
}
