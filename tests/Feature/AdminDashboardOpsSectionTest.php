<?php

namespace Tests\Feature;

use App\Models\LeadCampaign;
use App\Models\User;
use App\Models\Workflow;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardOpsSectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_dashboard_ops_section_renders_without_imports_widgets(): void
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

        Workflow::create([
            'workspace_id' => $workspace->id,
            'campaign_id' => $campaign->id,
            'name' => 'Import Batch 1',
            'original_filename' => 'import.csv',
            'status' => 'completed',
            'total_leads' => 10,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.dashboard', ['section' => 'ops']))
            ->assertOk()
            ->assertSee('Weekly leaderboard')
            ->assertDontSee('Data files & workflow performance');
    }

    public function test_admin_workflows_index_renders_import_leads_sections(): void
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

        Workflow::create([
            'workspace_id' => $workspace->id,
            'campaign_id' => $campaign->id,
            'name' => 'Import Batch 1',
            'original_filename' => 'import.csv',
            'status' => 'completed',
            'total_leads' => 10,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.workflows.index'))
            ->assertOk()
            ->assertSee('Data files & workflow performance')
            ->assertSee('Campaigns')
            ->assertSee('Active leads');
    }

    public function test_admin_layout_renders_sidebar_toggle_controls_and_nav_metadata(): void
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

        $this->actingAs($admin)
            ->get(route('admin.dashboard', ['section' => 'ops']))
            ->assertOk()
            ->assertSee('data-sidebar-toggle', false)
            ->assertSee('aria-controls="app-sidebar"', false)
            ->assertSee('data-nav-query-mode="empty"', false)
            ->assertSee('data-nav-match-prefixes=', false);
    }
}
