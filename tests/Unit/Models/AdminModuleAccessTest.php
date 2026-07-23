<?php

namespace Tests\Unit\Models;

use App\Models\User;
use App\Models\Workspace;
use App\Support\AdminModules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminModuleAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_null_module_permissions_grants_all_modules_for_admin(): void
    {
        [$workspace, $admin] = $this->makeAdminMember('admin@example.com', 'admin');

        $this->assertNull($admin->getModulePermissions($workspace->id));
        $this->assertTrue($admin->canAccessAdminModule('lead_pipeline', $workspace->id));
        $this->assertTrue($admin->canAccessAdminModule('email_lists', $workspace->id));
    }

    public function test_restricted_module_permissions_limit_sidebar_features(): void
    {
        [$workspace, $manager] = $this->makeAdminMember('manager@example.com', 'manager', ['email_lists', 'reputation']);

        $this->assertTrue($manager->canAccessAdminModule('dashboard', $workspace->id));
        $this->assertFalse($manager->canAccessAdminModule('lead_pipeline', $workspace->id));
        $this->assertTrue($manager->canAccessAdminModule('email_lists', $workspace->id));
        $this->assertTrue($manager->canAccessAdminModule('reputation', $workspace->id));
        $this->assertFalse($manager->canAccessAdminModule('user_management', $workspace->id));
    }

    public function test_default_admin_route_is_dashboard(): void
    {
        [$workspace, $manager] = $this->makeAdminMember('manager@example.com', 'manager', ['email_lists']);

        $this->assertSame('admin.dashboard', AdminModules::defaultRouteForUser($manager, $workspace->id));
    }

    public function test_empty_module_permissions_block_admin_portal_access(): void
    {
        [$workspace, $admin] = $this->makeAdminMember('limited@example.com', 'admin', []);

        $this->assertFalse($admin->canAccessAdminPortal($workspace->id));
    }

    public function test_workspace_owner_always_has_full_module_access(): void
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => bcrypt('password'),
        ]);

        $workspace = Workspace::create(['name' => 'Acme', 'admin_id' => $owner->id]);
        $owner->update(['current_workspace_id' => $workspace->id]);

        $this->assertTrue($owner->canAccessAdminModule('user_management', $workspace->id));
        $this->assertTrue($owner->canAccessAdminModule('lead_pipeline', $workspace->id));
    }

    public function test_admin_modules_route_resolution(): void
    {
        $this->assertSame('lead_pipeline', AdminModules::moduleForRoute('admin.workflows.index'));
        $this->assertSame('lead_pipeline', AdminModules::moduleForRoute('admin.assigned-leads'));
        $this->assertSame('email_lists', AdminModules::moduleForRoute('admin.lists.show'));
        $this->assertNull(AdminModules::moduleForRoute('admin.sync.poll'));
    }

    /**
     * @param  list<string>|null  $modules
     * @return array{0: Workspace, 1: User}
     */
    protected function makeAdminMember(string $email, string $role, ?array $modules = null): array
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => bcrypt('password'),
        ]);

        $workspace = Workspace::create(['name' => 'Acme', 'admin_id' => $owner->id]);

        $member = User::create([
            'name' => ucfirst(strtok($email, '@')),
            'email' => $email,
            'password' => bcrypt('password'),
            'current_workspace_id' => $workspace->id,
        ]);

        $workspace->users()->attach($member->id, [
            'role' => $role,
            'status' => 'active',
            'joined_at' => now(),
            'module_permissions' => $modules === null ? null : json_encode($modules),
        ]);

        return [$workspace, $member->fresh(['workspaces'])];
    }
}
