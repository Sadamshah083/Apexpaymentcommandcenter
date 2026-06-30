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

    public function test_admin_with_agent_context_in_one_workspace_can_access_admin_portal(): void
    {
        $user = User::factory()->create([
            'name' => 'dual_role_user',
            'password' => Hash::make('password123'),
        ]);

        $agentWorkspace = Workspace::create(['name' => 'Agent Only', 'admin_id' => User::factory()->create()->id]);
        $adminWorkspace = Workspace::create(['name' => 'Admin Co', 'admin_id' => $user->id]);

        $agentWorkspace->users()->attach($user->id, ['role' => 'sdr', 'status' => 'active', 'joined_at' => now()]);
        $adminWorkspace->users()->attach($user->id, ['role' => 'admin', 'status' => 'active', 'joined_at' => now()]);

        $user->update(['current_workspace_id' => $agentWorkspace->id]);

        $this->post(route('admin.login'), [
            'username' => 'dual_role_user',
            'password' => 'password123',
        ])->assertRedirect(route('admin.dashboard'));

        $user->refresh();
        $this->assertSame($adminWorkspace->id, $user->current_workspace_id);
        $this->get(route('admin.workspaces.index'))->assertOk();
    }

    public function test_admin_can_update_member_role_to_account_executive(): void
    {
        $admin = User::factory()->create(['name' => 'ws_admin', 'password' => Hash::make('password123')]);
        $workspace = Workspace::create(['name' => 'Acme', 'admin_id' => $admin->id]);
        $workspace->users()->attach($admin->id, ['role' => 'admin', 'status' => 'active', 'joined_at' => now()]);
        $admin->update(['current_workspace_id' => $workspace->id]);

        $agent = User::factory()->create(['name' => 'promoted_agent']);
        $workspace->users()->attach($agent->id, ['role' => 'sdr', 'status' => 'active', 'joined_at' => now()]);

        $this->actingAs($admin)
            ->patchJson(route('admin.workspaces.members.role', [$workspace, $agent]), [
                'role' => 'account_executive',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(
            'account_executive',
            $workspace->users()->where('user_id', $agent->id)->first()->pivot->role
        );
    }

    public function test_admin_can_reset_agent_password(): void
    {
        $admin = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Acme', 'admin_id' => $admin->id]);
        $workspace->users()->attach($admin->id, ['role' => 'admin', 'status' => 'active', 'joined_at' => now()]);
        $admin->update(['current_workspace_id' => $workspace->id]);

        $agent = User::factory()->create([
            'name' => 'reset_target',
            'password' => Hash::make('old-password'),
        ]);
        $workspace->users()->attach($agent->id, ['role' => 'sdr', 'status' => 'active', 'joined_at' => now()]);

        $this->actingAs($admin)
            ->postJson(route('admin.workspaces.members.reset-password', [$workspace, $agent]), [
                'password' => 'new-password-1',
                'password_confirmation' => 'new-password-1',
            ])
            ->assertOk();

        $agent->refresh();
        $this->assertTrue(Hash::check('new-password-1', $agent->password));

        $this->post(route('portal.login'), [
            'username' => 'reset_target',
            'password' => 'new-password-1',
        ])->assertRedirect(route('portal.dashboard'));
    }
}
