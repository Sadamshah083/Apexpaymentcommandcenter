<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceContextServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolve_active_workspace_creates_default_when_missing(): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);

        $service = new WorkspaceContextService();
        $workspace = $service->resolveActiveWorkspace($user);

        $this->assertNotNull($workspace->id);
        $this->assertEquals("Test User's Workspace", $workspace->name);
        $this->assertEquals($workspace->id, $user->fresh()->current_workspace_id);
        $this->assertTrue($workspace->users->contains($user));
    }

    public function test_resolve_active_workspace_reuses_existing_workspace(): void
    {
        $user = User::create([
            'name' => 'Existing User',
            'email' => 'existing@example.com',
            'password' => bcrypt('password'),
        ]);

        $workspace = Workspace::create([
            'name' => 'Existing Workspace',
            'admin_id' => $user->id,
        ]);
        $workspace->users()->attach($user->id, ['role' => 'admin']);
        $user->update(['current_workspace_id' => $workspace->id]);

        $service = new WorkspaceContextService();
        $resolved = $service->resolveActiveWorkspace($user);

        $this->assertEquals($workspace->id, $resolved->id);
        $this->assertEquals(1, Workspace::count());
    }

    public function test_ensure_workflow_belongs_to_workspace_aborts_for_foreign_workflow(): void
    {
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
        ]);

        $workspaceA = Workspace::create(['name' => 'A', 'admin_id' => $admin->id]);
        $workspaceB = Workspace::create(['name' => 'B', 'admin_id' => $admin->id]);

        $workflow = $workspaceB->workflows()->create([
            'name' => 'Foreign Workflow',
            'status' => 'mapping',
        ]);

        $service = new WorkspaceContextService();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $service->ensureWorkflowBelongsToWorkspace($workflow, $workspaceA);
    }
}
