<?php

namespace Tests\Unit\Services;

use App\Models\LeadTag;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowLead;
use App\Models\Workspace;
use App\Services\Pipeline\LeadSegmentationService;
use App\Services\Pipeline\LeadTagBatchService;
use App\Services\Pipeline\PipelineLeadReleaseService;
use App\Services\Workflow\WorkflowProviderStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LeadTagBatchServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_finds_leads_by_tag_across_workflows(): void
    {
        $admin = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Acme', 'admin_id' => $admin->id]);

        $tag = LeadTag::create(['workspace_id' => $workspace->id, 'name' => 'texas', 'color' => '#6366f1']);

        $workflowA = Workflow::create(['workspace_id' => $workspace->id, 'name' => 'File A', 'status' => 'completed']);
        $workflowB = Workflow::create(['workspace_id' => $workspace->id, 'name' => 'File B', 'status' => 'completed']);

        $leadA = WorkflowLead::create([
            'workflow_id' => $workflowA->id,
            'status' => 'imported',
            'row_number' => 1,
            'business_name' => 'A',
        ]);
        $leadB = WorkflowLead::create([
            'workflow_id' => $workflowB->id,
            'status' => 'imported',
            'row_number' => 1,
            'business_name' => 'B',
        ]);

        $leadA->tags()->attach($tag->id);
        $leadB->tags()->attach($tag->id);

        $service = $this->makeService();
        $counts = $service->countByStatus($workspace, [$tag->id]);

        $this->assertSame(2, $counts['total']);
        $this->assertSame(2, $counts['imported']);
    }

    public function test_enrich_by_tags_dispatches_jobs(): void
    {
        Queue::fake();
        config(['openrouter.api_key' => 'test-key']);

        $admin = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Acme', 'admin_id' => $admin->id]);
        $tag = LeadTag::create(['workspace_id' => $workspace->id, 'name' => 'batch', 'color' => '#6366f1']);
        $workflow = Workflow::create(['workspace_id' => $workspace->id, 'name' => 'Import', 'status' => 'completed']);
        $lead = WorkflowLead::create([
            'workflow_id' => $workflow->id,
            'status' => 'imported',
            'row_number' => 1,
            'business_name' => 'Biz',
        ]);
        $lead->tags()->attach($tag->id);

        $service = $this->makeService();
        $count = $service->enrichByTags($workspace, [$tag->id]);

        $this->assertSame(1, $count);
        Queue::assertPushed(\App\Jobs\ProcessLeadJob::class);
    }

    protected function makeService(): LeadTagBatchService
    {
        return new LeadTagBatchService(
            app(PipelineLeadReleaseService::class),
            app(LeadSegmentationService::class),
            new WorkflowProviderStatusService,
        );
    }
}
