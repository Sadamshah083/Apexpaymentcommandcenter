<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class WorkspaceAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_can_log_into_marketer_portal_only(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Acme', 'admin_id' => $owner->id]);
        $workspace->users()->attach($owner->id, ['role' => 'admin', 'status' => 'active', 'joined_at' => now()]);

        $agent = User::factory()->create([
            'name' => 'agent_user',
            'password' => Hash::make('password123'),
            'current_workspace_id' => $workspace->id,
        ]);
        $workspace->users()->attach($agent->id, ['role' => 'marketer', 'status' => 'active', 'joined_at' => now()]);

        $this->post(route('portal.login'), [
            'username' => 'agent_user',
            'password' => 'password123',
        ])->assertRedirect(route('portal.dashboard'));

        $this->post(route('admin.logout'));

        $this->post(route('admin.login'), [
            'username' => 'agent_user',
            'password' => 'password123',
        ])->assertSessionHasErrors('username');
    }

    public function test_admin_can_log_into_admin_and_marketer_portals(): void
    {
        $admin = User::factory()->create([
            'name' => 'admin_user',
            'password' => Hash::make('password123'),
        ]);
        $workspace = Workspace::create(['name' => 'Acme', 'admin_id' => $admin->id]);
        $workspace->users()->attach($admin->id, ['role' => 'admin', 'status' => 'active', 'joined_at' => now()]);
        $admin->update(['current_workspace_id' => $workspace->id]);

        $this->post(route('admin.login'), [
            'username' => 'admin_user',
            'password' => 'password123',
        ])->assertRedirect(route('admin.dashboard'));

        $this->post(route('admin.logout'));

        $this->post(route('portal.login'), [
            'username' => 'admin_user',
            'password' => 'password123',
        ])->assertRedirect(route('portal.dashboard'));
    }

    public function test_suspended_agent_cannot_log_into_marketer_portal(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Acme', 'admin_id' => $owner->id]);
        $workspace->users()->attach($owner->id, ['role' => 'admin', 'status' => 'active', 'joined_at' => now()]);

        $agent = User::factory()->create([
            'name' => 'suspended_agent',
            'password' => Hash::make('password123'),
            'current_workspace_id' => $workspace->id,
        ]);
        $workspace->users()->attach($agent->id, ['role' => 'marketer', 'status' => 'suspended', 'joined_at' => now()]);

        $this->post(route('portal.login'), [
            'username' => 'suspended_agent',
            'password' => 'password123',
        ])->assertSessionHasErrors('username');

        $errors = session('errors');
        $this->assertStringContainsString('suspended', strtolower($errors->get('username')[0]));
    }

    public function test_suspended_agent_with_active_workspace_elsewhere_can_still_log_in(): void
    {
        $owner = User::factory()->create();
        $suspendedWorkspace = Workspace::create(['name' => 'Paused Co', 'admin_id' => $owner->id]);
        $activeWorkspace = Workspace::create(['name' => 'Active Co', 'admin_id' => $owner->id]);
        $owner->update(['current_workspace_id' => $activeWorkspace->id]);

        $agent = User::factory()->create([
            'name' => 'multi_ws_agent',
            'password' => Hash::make('password123'),
            'current_workspace_id' => $suspendedWorkspace->id,
        ]);

        $suspendedWorkspace->users()->attach($agent->id, ['role' => 'marketer', 'status' => 'suspended', 'joined_at' => now()]);
        $activeWorkspace->users()->attach($agent->id, ['role' => 'marketer', 'status' => 'active', 'joined_at' => now()]);

        $this->post(route('portal.login'), [
            'username' => 'multi_ws_agent',
            'password' => 'password123',
        ])->assertRedirect(route('portal.dashboard'));

        $agent->refresh();
        $this->assertSame($activeWorkspace->id, $agent->current_workspace_id);
    }
}
