<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowLead;
use App\Models\Workspace;
use App\Services\Workflow\WorkflowAiMapper;
use App\Services\Workspace\WorkspaceSyncService;
use App\Services\Workflow\WorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class WorkflowServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_queue_for_processing_requires_business_name_mapping(): void
    {
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
        ]);

        $workspace = Workspace::create(['name' => 'Sales', 'admin_id' => $admin->id]);
        $workflow = Workflow::create([
            'workspace_id' => $workspace->id,
            'name' => 'Pipeline',
            'status' => 'mapping',
        ]);

        $sync = Mockery::mock(WorkspaceSyncService::class);
        $sync->shouldReceive('record')->zeroOrMoreTimes();

        $service = new WorkflowService(Mockery::mock(WorkflowAiMapper::class), $sync);

        $this->expectException(ValidationException::class);
        $service->queueForProcessing($workflow, ['mapping' => []]);
    }

    public function test_queue_for_processing_dispatches_job(): void
    {
        Queue::fake();

        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin2@example.com',
            'password' => bcrypt('password'),
        ]);

        $workspace = Workspace::create(['name' => 'Sales', 'admin_id' => $admin->id]);
        $workflow = Workflow::create([
            'workspace_id' => $workspace->id,
            'name' => 'Pipeline',
            'status' => 'mapping',
            'file_path' => 'workflows/test.csv',
        ]);

        $sync = Mockery::mock(WorkspaceSyncService::class);
        $sync->shouldReceive('record')->zeroOrMoreTimes();

        $service = new WorkflowService(Mockery::mock(WorkflowAiMapper::class), $sync);
        $service->queueForProcessing($workflow, [
            'mapping' => ['business_name' => 'Company'],
            'custom_prompt' => 'Find owner details',
        ]);

        $workflow->refresh();
        $this->assertEquals('pending', $workflow->status);
        $this->assertEquals(['business_name' => 'Company'], $workflow->column_mapping);
        Queue::assertPushed(\App\Jobs\ProcessWorkflowJob::class);
    }

    public function test_queue_for_processing_blocks_while_pipeline_is_running(): void
    {
        $admin = User::create([
            'name' => 'Admin Running',
            'email' => 'admin-running@example.com',
            'password' => bcrypt('password'),
        ]);

        $workspace = Workspace::create(['name' => 'Sales', 'admin_id' => $admin->id]);
        $workflow = Workflow::create([
            'workspace_id' => $workspace->id,
            'name' => 'Pipeline',
            'status' => 'extracting',
            'file_path' => 'workflows/test.csv',
        ]);

        $sync = Mockery::mock(WorkspaceSyncService::class);
        $service = new WorkflowService(Mockery::mock(WorkflowAiMapper::class), $sync);

        $this->expectException(ValidationException::class);
        $service->queueForProcessing($workflow, [
            'mapping' => ['business_name' => 'Company'],
        ]);
    }

    public function test_queue_for_processing_resets_ingestion_state(): void
    {
        Queue::fake();

        $admin = User::create([
            'name' => 'Admin Reset',
            'email' => 'admin-reset@example.com',
            'password' => bcrypt('password'),
        ]);

        $workspace = Workspace::create(['name' => 'Sales', 'admin_id' => $admin->id]);
        $workflow = Workflow::create([
            'workspace_id' => $workspace->id,
            'name' => 'Pipeline',
            'status' => 'failed',
            'file_path' => 'workflows/test.csv',
            'ingestion_row_offset' => 10,
            'ingestion_complete' => true,
            'total_leads' => 5,
            'processed_leads' => 3,
        ]);

        WorkflowLead::create([
            'workflow_id' => $workflow->id,
            'status' => 'completed',
            'row_number' => 1,
            'business_name' => 'Old Lead',
        ]);

        $sync = Mockery::mock(WorkspaceSyncService::class);
        $sync->shouldReceive('record')->once();

        $service = new WorkflowService(Mockery::mock(WorkflowAiMapper::class), $sync);
        $service->queueForProcessing($workflow, [
            'mapping' => ['business_name' => 'Company'],
        ]);

        $workflow->refresh();
        $this->assertEquals(0, $workflow->ingestion_row_offset);
        $this->assertFalse($workflow->ingestion_complete);
        $this->assertEquals(0, $workflow->total_leads);
        $this->assertDatabaseMissing('workflow_leads', ['business_name' => 'Old Lead']);
    }

    public function test_delete_removes_uploaded_file(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('workflows/test.csv', 'name,email');

        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin3@example.com',
            'password' => bcrypt('password'),
        ]);

        $workspace = Workspace::create(['name' => 'Sales', 'admin_id' => $admin->id]);
        $workflow = Workflow::create([
            'workspace_id' => $workspace->id,
            'name' => 'Pipeline',
            'status' => 'mapping',
            'file_path' => 'workflows/test.csv',
        ]);

        $sync = Mockery::mock(WorkspaceSyncService::class);
        $sync->shouldReceive('record')->zeroOrMoreTimes();

        $service = new WorkflowService(Mockery::mock(WorkflowAiMapper::class), $sync);
        $service->delete($workflow);

        Storage::disk('local')->assertMissing('workflows/test.csv');
        $this->assertDatabaseMissing('workflows', ['id' => $workflow->id]);
    }

    public function test_delete_removes_all_lead_records_from_database(): void
    {
        Storage::fake('local');

        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin-delete-leads@example.com',
            'password' => bcrypt('password'),
        ]);

        $workspace = Workspace::create(['name' => 'Sales', 'admin_id' => $admin->id]);
        $workflow = Workflow::create([
            'workspace_id' => $workspace->id,
            'name' => 'Pipeline',
            'status' => 'completed',
            'total_leads' => 2,
        ]);

        $leadIds = [];
        foreach ([1, 2] as $row) {
            $leadIds[] = WorkflowLead::create([
                'workflow_id' => $workflow->id,
                'status' => 'completed',
                'row_number' => $row,
                'business_name' => "Business {$row}",
            ])->id;
        }

        $sync = Mockery::mock(WorkspaceSyncService::class);
        $sync->shouldReceive('record')->zeroOrMoreTimes();

        $service = new WorkflowService(Mockery::mock(WorkflowAiMapper::class), $sync);
        $service->delete($workflow);

        $this->assertDatabaseMissing('workflows', ['id' => $workflow->id]);
        foreach ($leadIds as $leadId) {
            $this->assertDatabaseMissing('workflow_leads', ['id' => $leadId]);
        }
    }

    public function test_pause_processing_sets_workflow_paused_and_resets_extracting_leads(): void
    {
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin-pause@example.com',
            'password' => bcrypt('password'),
        ]);

        $workspace = Workspace::create(['name' => 'Sales', 'admin_id' => $admin->id]);
        $workflow = Workflow::create([
            'workspace_id' => $workspace->id,
            'name' => 'Pipeline',
            'status' => 'extracting',
            'total_leads' => 3,
        ]);

        $extractingLead = WorkflowLead::create([
            'workflow_id' => $workflow->id,
            'status' => 'extracting',
            'row_number' => 1,
            'business_name' => 'Active Lead',
        ]);

        $sync = Mockery::mock(WorkspaceSyncService::class);
        $sync->shouldReceive('record')->once();

        $service = new WorkflowService(Mockery::mock(WorkflowAiMapper::class), $sync);
        $service->pauseProcessing($workflow);

        $workflow->refresh();
        $extractingLead->refresh();

        $this->assertEquals('paused', $workflow->status);
        $this->assertEquals('pending', $extractingLead->status);
    }

    public function test_resume_processing_dispatches_pending_lead_jobs(): void
    {
        Queue::fake();

        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin-resume@example.com',
            'password' => bcrypt('password'),
        ]);

        $workspace = Workspace::create(['name' => 'Sales', 'admin_id' => $admin->id]);
        $workflow = Workflow::create([
            'workspace_id' => $workspace->id,
            'name' => 'Pipeline',
            'status' => 'paused',
            'total_leads' => 2,
            'processed_leads' => 1,
            'custom_prompt' => 'Find owner',
        ]);

        WorkflowLead::create([
            'workflow_id' => $workflow->id,
            'status' => 'completed',
            'row_number' => 1,
            'business_name' => 'Done Lead',
        ]);

        $pendingLead = WorkflowLead::create([
            'workflow_id' => $workflow->id,
            'status' => 'pending',
            'row_number' => 2,
            'business_name' => 'Pending Lead',
        ]);

        $sync = Mockery::mock(WorkspaceSyncService::class);
        $sync->shouldReceive('record')->once();

        $service = new WorkflowService(Mockery::mock(WorkflowAiMapper::class), $sync);
        $service->resumeProcessing($workflow);

        $workflow->refresh();
        $this->assertEquals('extracting', $workflow->status);

        Queue::assertPushed(\App\Jobs\ProcessLeadJob::class, function ($job) use ($pendingLead) {
            return $job->leadId === $pendingLead->id;
        });
    }

    public function test_pause_requires_running_pipeline(): void
    {
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin-pause-invalid@example.com',
            'password' => bcrypt('password'),
        ]);

        $workspace = Workspace::create(['name' => 'Sales', 'admin_id' => $admin->id]);
        $workflow = Workflow::create([
            'workspace_id' => $workspace->id,
            'name' => 'Pipeline',
            'status' => 'completed',
        ]);

        $sync = Mockery::mock(WorkspaceSyncService::class);
        $service = new WorkflowService(Mockery::mock(WorkflowAiMapper::class), $sync);

        $this->expectException(ValidationException::class);
        $service->pauseProcessing($workflow);
    }

    public function test_create_from_upload_persists_workflow_metadata(): void
    {
        Storage::fake('local');

        $mapper = Mockery::mock(WorkflowAiMapper::class);
        $mapper->shouldReceive('getFileSheets')
            ->once()
            ->andReturn(['Sheet1', 'Sheet2']);

        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin4@example.com',
            'password' => bcrypt('password'),
        ]);

        $workspace = Workspace::create(['name' => 'Sales', 'admin_id' => $admin->id]);
        $file = UploadedFile::fake()->create('leads.xlsx', 10, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $sync = Mockery::mock(WorkspaceSyncService::class);
        $service = new WorkflowService($mapper, $sync);
        $workflow = $service->createFromUpload($workspace, 'Q2 Leads', $file);

        $this->assertEquals('Q2 Leads', $workflow->name);
        $this->assertEquals('mapping', $workflow->status);
        $this->assertEquals(['Sheet1', 'Sheet2'], $workflow->sheets);
        $this->assertEquals('Sheet1', $workflow->selected_sheet);
        $this->assertNotNull($workflow->file_path);
    }
}
