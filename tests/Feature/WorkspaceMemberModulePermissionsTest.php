<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceMemberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WorkspaceMemberModulePermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_restrict_manager_modules(): void
    {
        [$workspace, $owner, $manager] = $this->seedWorkspaceTeam();

        app(WorkspaceMemberService::class)->updateMemberModules(
            $workspace,
            $owner,
            $manager,
            ['lead_pipeline', 'lead_tags'],
            true,
        );

        $manager = $manager->fresh(['workspaces']);

        $this->assertSame(['lead_pipeline', 'lead_tags'], $manager->getModulePermissions($workspace->id));
        $this->assertTrue($manager->canAccessAdminModule('lead_pipeline', $workspace->id));
        $this->assertFalse($manager->canAccessAdminModule('email_lists', $workspace->id));
    }

    public function test_admin_cannot_grant_user_management_module(): void
    {
        [$workspace, , $manager] = $this->seedWorkspaceTeam(withAdmin: true);

        $admin = User::where('email', 'admin@example.com')->firstOrFail();

        $this->expectException(ValidationException::class);

        app(WorkspaceMemberService::class)->updateMemberModules(
            $workspace,
            $admin,
            $manager,
            ['user_management'],
            true,
        );
    }

    /**
     * @return array{0: Workspace, 1: User, 2: User}
     */
    protected function seedWorkspaceTeam(bool $withAdmin = false): array
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => bcrypt('password'),
        ]);

        $workspace = Workspace::create(['name' => 'Acme', 'admin_id' => $owner->id]);
        $owner->update(['current_workspace_id' => $workspace->id]);

        if ($withAdmin) {
            $delegate = User::create([
                'name' => 'Admin',
                'email' => 'admin@example.com',
                'password' => bcrypt('password'),
                'current_workspace_id' => $workspace->id,
            ]);

            $workspace->users()->attach($delegate->id, [
                'role' => 'admin',
                'status' => 'active',
                'joined_at' => now(),
            ]);
        }

        $manager = User::create([
            'name' => 'Manager',
            'email' => 'manager@example.com',
            'password' => bcrypt('password'),
            'current_workspace_id' => $workspace->id,
        ]);

        $workspace->users()->attach($manager->id, [
            'role' => 'manager',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return [$workspace, $owner, $manager];
    }
}
