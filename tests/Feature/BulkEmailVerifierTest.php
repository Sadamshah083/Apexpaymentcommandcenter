<?php

namespace Tests\Feature;

use App\Jobs\ProcessListUploadJob;
use App\Models\EmailList;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BulkEmailVerifierTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_can_upload_email_list_scoped_to_workspace(): void
    {
        Queue::fake();
        Storage::fake('local');

        $owner = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Acme', 'admin_id' => $owner->id]);
        $workspace->users()->attach($owner->id, ['role' => 'admin', 'status' => 'active', 'joined_at' => now()]);

        $agent = User::factory()->create([
            'name' => 'agent_user',
            'password' => Hash::make('password123'),
            'current_workspace_id' => $workspace->id,
        ]);
        $workspace->users()->attach($agent->id, ['role' => 'marketer', 'status' => 'active', 'joined_at' => now()]);

        $this->actingAs($agent);

        $path = 'uploads/test.csv';
        Storage::disk('local')->put($path, "user@example.com\nbad@not-a-real-domain.test\n");

        $response = $this->post(route('portal.lists.store'), [
            'name' => 'June batch',
            'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('emails.csv', "user@gmail.com\n"),
        ]);

        $list = EmailList::first();
        $this->assertNotNull($list);
        $response->assertRedirect(route('portal.lists.show', $list));

        $this->assertSame($workspace->id, $list->workspace_id);
        $this->assertSame($agent->id, $list->user_id);
        $this->assertSame('June batch', $list->name);

        Queue::assertPushed(ProcessListUploadJob::class);
    }

    public function test_agent_cannot_view_list_from_another_workspace(): void
    {
        $ownerA = User::factory()->create(['current_workspace_id' => null]);
        $workspaceA = Workspace::create(['name' => 'A', 'admin_id' => $ownerA->id]);
        $workspaceA->users()->attach($ownerA->id, ['role' => 'admin', 'status' => 'active', 'joined_at' => now()]);
        $ownerA->update(['current_workspace_id' => $workspaceA->id]);

        $ownerB = User::factory()->create();
        $workspaceB = Workspace::create(['name' => 'B', 'admin_id' => $ownerB->id]);
        $workspaceB->users()->attach($ownerB->id, ['role' => 'admin', 'status' => 'active', 'joined_at' => now()]);
        $ownerB->update(['current_workspace_id' => $workspaceB->id]);

        $foreignList = EmailList::create([
            'workspace_id' => $workspaceB->id,
            'user_id' => $ownerB->id,
            'name' => 'Foreign',
            'status' => 'completed',
        ]);

        $this->actingAs($ownerA)
            ->get(route('admin.lists.show', $foreignList))
            ->assertForbidden();
    }
}
