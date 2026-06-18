<?php

namespace Tests\Unit\Models;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_switchable_workspaces_includes_active_memberships_and_owned_workspaces(): void
    {
        $user = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => bcrypt('password'),
        ]);

        $primary = Workspace::create(['name' => 'Alpha', 'admin_id' => $user->id]);
        $primary->users()->attach($user->id, ['role' => 'admin', 'status' => 'active']);

        $ownedWithoutPivot = Workspace::create(['name' => 'Beta', 'admin_id' => $user->id]);

        $otherOwner = User::create([
            'name' => 'Other',
            'email' => 'other@example.com',
            'password' => bcrypt('password'),
        ]);
        $memberWorkspace = Workspace::create(['name' => 'Gamma', 'admin_id' => $otherOwner->id]);
        $memberWorkspace->users()->attach($user->id, ['role' => 'marketer', 'status' => 'active']);

        $suspendedWorkspace = Workspace::create(['name' => 'Delta', 'admin_id' => $otherOwner->id]);
        $suspendedWorkspace->users()->attach($user->id, ['role' => 'marketer', 'status' => 'suspended']);

        $switchable = $user->switchableWorkspaces();

        $this->assertEquals(['Alpha', 'Beta', 'Gamma'], $switchable->pluck('name')->all());
    }

    public function test_workspace_owner_without_pivot_can_switch_workspace(): void
    {
        $user = User::create([
            'name' => 'Owner',
            'email' => 'owner2@example.com',
            'password' => bcrypt('password'),
        ]);

        $workspace = Workspace::create(['name' => 'Legacy Workspace', 'admin_id' => $user->id]);

        $service = new WorkspaceContextService();
        $service->ensureUserIsMember($user, $workspace);

        $this->assertTrue($workspace->users()->where('user_id', $user->id)->exists());
    }
}
