<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceSyncAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sync_poll_includes_workspace_context_and_collaborators(): void
    {
        $admin = User::factory()->create(['name' => 'owner']);
        $workspace = Workspace::create([
            'name' => 'Primary Workspace',
            'admin_id' => $admin->id,
        ]);
        $workspace->users()->attach($admin->id, [
            'role' => 'admin',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $admin->update(['current_workspace_id' => $workspace->id]);

        $agent = User::factory()->create(['name' => 'agent_one']);
        $workspace->users()->attach($agent->id, [
            'role' => 'marketer',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $response = $this->actingAs($admin)->getJson(route('admin.sync.poll'));

        $response->assertOk()
            ->assertJsonPath('workspace_context.name', 'Primary Workspace')
            ->assertJsonPath('workspace_context.member_count', 2)
            ->assertJsonCount(1, 'workspaces')
            ->assertJsonPath('workspaces.0.is_active', true);

        $team = collect($response->json('team'));
        $this->assertTrue($team->contains(fn (array $member) => $member['name'] === 'agent_one' && $member['can_manage'] === true));
        $this->assertTrue($team->contains(fn (array $member) => $member['is_owner'] === true));
    }

    public function test_admin_sync_poll_includes_sales_ops_overview(): void
    {
        $admin = User::factory()->create();
        $workspace = Workspace::create([
            'name' => 'Ops',
            'admin_id' => $admin->id,
        ]);
        $workspace->users()->attach($admin->id, [
            'role' => 'admin',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $admin->update(['current_workspace_id' => $workspace->id]);

        $response = $this->actingAs($admin)->getJson(route('admin.sync.poll'));

        $response->assertOk()
            ->assertJsonStructure([
                'changed',
                'version',
                'sales_ops' => [
                    'overview' => ['total_active_leads', 'pending_verification', 'reactivation_queue'],
                    'leaderboard',
                    'sdr_load',
                ],
            ]);
    }

    public function test_sync_poll_skips_payload_when_version_unchanged(): void
    {
        $admin = User::factory()->create();
        $workspace = Workspace::create([
            'name' => 'Ops',
            'admin_id' => $admin->id,
        ]);
        $workspace->users()->attach($admin->id, [
            'role' => 'admin',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $admin->update(['current_workspace_id' => $workspace->id]);

        $first = $this->actingAs($admin)->getJson(route('admin.sync.poll'));
        $version = $first->json('version');
        $cursor = $first->json('cursor');

        $second = $this->actingAs($admin)->getJson(route('admin.sync.poll', [
            'v' => $version,
            'cursor' => $cursor,
        ]));

        $second->assertOk()
            ->assertJsonPath('changed', false)
            ->assertJsonPath('version', $version)
            ->assertJsonMissingPath('sales_ops');
    }

    public function test_member_suspend_returns_json_for_ajax_requests(): void
    {
        $admin = User::factory()->create(['name' => 'owner']);
        $workspace = Workspace::create([
            'name' => 'Ops',
            'admin_id' => $admin->id,
        ]);
        $workspace->users()->attach($admin->id, [
            'role' => 'admin',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $admin->update(['current_workspace_id' => $workspace->id]);

        $agent = User::factory()->create(['name' => 'agent_one']);
        $workspace->users()->attach($agent->id, [
            'role' => 'marketer',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $response = $this->actingAs($admin)->postJson(
            route('admin.workspaces.members.suspend', [$workspace, $agent]),
        );

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('suspended', $workspace->users()->where('user_id', $agent->id)->first()->pivot->status);
    }

    public function test_workspace_creation_records_sync_event(): void
    {
        $admin = User::factory()->create(['name' => 'owner']);
        $workspace = Workspace::create([
            'name' => 'Starter',
            'admin_id' => $admin->id,
        ]);
        $workspace->users()->attach($admin->id, [
            'role' => 'admin',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $admin->update(['current_workspace_id' => $workspace->id]);

        $this->actingAs($admin)->post(route('admin.workspaces.store'), [
            'name' => 'Client B',
        ])->assertRedirect(route('admin.workspaces.index'));

        $this->assertDatabaseHas('workspace_sync_events', [
            'event_type' => 'workspace.created',
            'entity_type' => 'workspace',
        ]);
    }

    public function test_sync_poll_includes_pipeline_steps_for_workflow(): void
    {
        $admin = User::factory()->create();
        $workspace = Workspace::create([
            'name' => 'Ops',
            'admin_id' => $admin->id,
        ]);
        $workspace->users()->attach($admin->id, [
            'role' => 'admin',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $admin->update(['current_workspace_id' => $workspace->id]);

        $workflow = \App\Models\Workflow::create([
            'workspace_id' => $workspace->id,
            'name' => 'Test Import',
            'status' => 'extracting',
            'total_leads' => 10,
            'processed_leads' => 3,
        ]);

        $response = $this->actingAs($admin)->getJson(route('admin.sync.poll', [
            'workflow_id' => $workflow->id,
        ]));

        $response->assertOk()
            ->assertJsonPath('workflows.0.pipeline_steps.0.key', 'import')
            ->assertJsonPath('workflows.0.pipeline_steps.1.key', 'enrich');
    }
}
