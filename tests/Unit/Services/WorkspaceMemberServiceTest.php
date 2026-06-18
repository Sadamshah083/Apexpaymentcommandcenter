<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceMemberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class WorkspaceMemberServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_agent_with_username_and_password(): void
    {
        $owner = User::factory()->create(['name' => 'owner']);
        $workspace = Workspace::create([
            'name' => 'Acme Corp',
            'admin_id' => $owner->id,
        ]);
        $workspace->users()->attach($owner->id, [
            'role' => 'admin',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $owner->update(['current_workspace_id' => $workspace->id]);

        $agent = app(WorkspaceMemberService::class)->createAgent(
            $workspace,
            $owner,
            'agent_one',
            'secret123',
            'marketer',
        );

        $this->assertSame('agent_one', $agent->name);
        $this->assertTrue(Hash::check('secret123', $agent->password));
        $this->assertSame('marketer', $agent->getWorkspaceRole($workspace->id));
        $this->assertTrue($agent->canAccessMarketerPortal($workspace->id));
        $this->assertFalse($agent->canAccessAdminPortal($workspace->id));
    }

    public function test_admin_can_access_both_portals(): void
    {
        $owner = User::factory()->create(['name' => 'owner']);
        $workspace = Workspace::create([
            'name' => 'Acme Corp',
            'admin_id' => $owner->id,
        ]);
        $workspace->users()->attach($owner->id, [
            'role' => 'admin',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $owner->update(['current_workspace_id' => $workspace->id]);

        $this->assertTrue($owner->canAccessAdminPortal($workspace->id));
        $this->assertTrue($owner->canAccessMarketerPortal($workspace->id));
    }
}
