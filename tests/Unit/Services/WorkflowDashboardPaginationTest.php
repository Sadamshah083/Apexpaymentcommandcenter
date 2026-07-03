<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowLead;
use App\Models\Workspace;
use App\Services\Workflow\WorkflowDashboardService;
use App\Services\Workflow\WorkflowProviderStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowDashboardPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_paginates_leads_and_workflows_separately(): void
    {
        $admin = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Test WS', 'admin_id' => $admin->id]);
        $workspace->users()->attach($admin->id, ['role' => 'admin', 'status' => 'active', 'joined_at' => now()]);
        $admin->update(['current_workspace_id' => $workspace->id]);

        $workflow = Workflow::create([
            'workspace_id' => $workspace->id,
            'name' => 'Batch',
            'original_filename' => 'leads.csv',
            'file_path' => 'workflows/test.csv',
            'status' => 'completed',
            'total_leads' => 30,
        ]);

        for ($i = 1; $i <= 30; $i++) {
            WorkflowLead::create([
                'workflow_id' => $workflow->id,
                'status' => 'completed',
                'row_number' => $i,
                'business_name' => "Business {$i}",
            ]);
        }

        for ($i = 1; $i <= 10; $i++) {
            Workflow::create([
                'workspace_id' => $workspace->id,
                'name' => "Pipeline {$i}",
                'original_filename' => "file-{$i}.csv",
                'file_path' => "workflows/file-{$i}.csv",
                'status' => 'completed',
            ]);
        }

        $service = new WorkflowDashboardService(new WorkflowProviderStatusService);
        $data = $service->buildIndexData($workspace, $admin);

        $this->assertSame(20, $data['leads']->perPage());
        $this->assertSame(30, $data['leads']->total());
        $this->assertSame(20, $data['workflows']->perPage());
        $this->assertSame(11, $data['workflows']->total());
    }
}
