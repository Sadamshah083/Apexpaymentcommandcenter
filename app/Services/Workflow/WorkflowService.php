<?php

namespace App\Services\Workflow;

use App\Jobs\ProcessLeadJob;
use App\Jobs\ProcessWorkflowJob;
use App\Models\Workflow;
use App\Models\WorkflowLead;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceSyncService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class WorkflowService
{
    public function __construct(
        protected WorkflowAiMapper $aiMapper,
        protected WorkspaceSyncService $syncService,
    ) {}

    public function createFromUpload(Workspace $workspace, string $name, UploadedFile $file, string $processingMode = 'full_pipeline'): Workflow
    {
        $storedPath = $file->store('workflows');
        $sheets = [];

        try {
            $sheets = $this->aiMapper->getFileSheets(Storage::disk('local')->path($storedPath));
        } catch (\Throwable $e) {
            Log::debug('Workflow upload has no spreadsheet sheets', [
                'workspace_id' => $workspace->id,
                'filename' => $file->getClientOriginalName(),
                'error' => $e->getMessage(),
            ]);
        }

        return Workflow::create([
            'workspace_id' => $workspace->id,
            'name' => $name,
            'processing_mode' => in_array($processingMode, ['store_only', 'full_pipeline'], true)
                ? $processingMode
                : 'full_pipeline',
            'original_filename' => $file->getClientOriginalName(),
            'file_path' => $storedPath,
            'sheets' => empty($sheets) ? null : $sheets,
            'selected_sheet' => empty($sheets) ? null : $sheets[0],
            'status' => 'mapping',
        ]);
    }

    public function applyAutoMappingIfNeeded(Workflow $workflow): Workflow
    {
        if ($workflow->status !== 'mapping') {
            return $workflow;
        }

        $mapping = $workflow->column_mapping ?? [];
        if (! empty($mapping['business_name'])) {
            return $workflow;
        }

        if (! $workflow->file_path || ! Storage::disk('local')->exists($workflow->file_path)) {
            return $workflow;
        }

        try {
            $autoMap = $this->aiMapper->autoMap(
                Storage::disk('local')->path($workflow->file_path),
                $workflow->selected_sheet
            );

            $resolvedMapping = $autoMap['mapping'] ?? [];

            if (empty($resolvedMapping['business_name']) && ! empty($autoMap['headers'])) {
                $resolvedMapping = $this->aiMapper->mergeMappings(
                    $this->aiMapper->heuristicMap($autoMap['headers']),
                    $resolvedMapping,
                    $autoMap['headers']
                );
            }

            if (! empty(array_filter($resolvedMapping))) {
                $workflow->update([
                    'column_mapping' => $resolvedMapping,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Workflow auto-mapping failed', [
                'workflow_id' => $workflow->id,
                'error' => $e->getMessage(),
            ]);

            $headers = $this->resolveHeaderRow($workflow);
            if (! empty($headers)) {
                $fallback = $this->aiMapper->heuristicMap($headers);
                if (! empty($fallback['business_name'])) {
                    $workflow->update(['column_mapping' => $fallback]);
                }
            }
        }

        return $workflow->fresh();
    }

    /**
     * @return array{
     *     workflow: Workflow,
     *     leads: \Illuminate\Contracts\Pagination\LengthAwarePaginator,
     *     headers: array<int, mixed>,
     *     team: \Illuminate\Database\Eloquent\Collection
     * }
     */
    public function buildShowData(Workflow $workflow, Workspace $workspace, array $options = []): array
    {
        $workflow = $this->applyAutoMappingIfNeeded($workflow);
        $workflow->loadCount([
            'leads as assigned_leads_count' => fn ($query) => $query->whereNotNull('assigned_user_id'),
            'leads as pending_verification_count' => fn ($query) => $query->where('status', 'pending_verification'),
        ]);

        $perPage = min(max((int) ($options['per_page'] ?? config('pagination.pipeline_leads_per_page', 25)), 5), 100);

        $leads = $workflow->leads()
            ->orderByRaw("CASE WHEN status = 'pending_verification' THEN 0 WHEN status = 'extracting' THEN 1 WHEN status = 'completed' THEN 2 WHEN status = 'failed' THEN 3 ELSE 4 END ASC")
            ->orderBy('researched_at', 'desc')
            ->orderBy('row_number', 'asc')
            ->paginate($perPage)
            ->withQueryString();

        return [
            'workflow' => $workflow,
            'leads' => $leads,
            'headers' => $this->resolveHeaderRow($workflow),
            'team' => $workspace->users()
                ->wherePivot('role', 'appointment_setter')
                ->wherePivot('status', 'active')
                ->get(),
        ];
    }

    protected function resolveHeaderRow(Workflow $workflow): array
    {
        if (! $workflow->file_path || ! Storage::disk('local')->exists($workflow->file_path)) {
            return [];
        }

        try {
            return $this->aiMapper->getHeaderRow(
                Storage::disk('local')->path($workflow->file_path),
                $workflow->selected_sheet
            );
        } catch (\Throwable $e) {
            Log::debug('Workflow header row unavailable', [
                'workflow_id' => $workflow->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    public function updateMapping(Workflow $workflow, array $mapping, ?string $selectedSheet = null): void
    {
        if ($selectedSheet !== null) {
            $workflow->selected_sheet = $selectedSheet;
        }

        $workflow->column_mapping = $mapping;
        $workflow->save();
    }

    /**
     * @param  array{
     *     mapping: array<string, mixed>,
     *     custom_prompt?: string|null,
     *     verification_toggles?: mixed,
     *     distribution_users?: mixed
     * }  $runConfig
     */
    public function queueForProcessing(Workflow $workflow, array $runConfig): void
    {
        $mapping = $runConfig['mapping'] ?? [];

        if (empty($mapping['business_name'])) {
            throw ValidationException::withMessages([
                'mapping' => 'Please map at least the Business Name column.',
            ]);
        }

        if (empty($runConfig['mapping_confirmed'])) {
            throw ValidationException::withMessages([
                'mapping_confirmed' => 'Confirm you have reviewed the column mapping before launching the pipeline.',
            ]);
        }

        if (in_array($workflow->status, ['pending', 'extracting'], true)) {
            throw ValidationException::withMessages([
                'workflow' => 'This pipeline is already processing. Stop it first if you need to change configuration.',
            ]);
        }

        if ($workflow->isPaused()) {
            throw ValidationException::withMessages([
                'workflow' => 'This pipeline is paused. Resume it from the pipeline list instead of starting a new run.',
            ]);
        }

        $workflow->leads()->delete();

        $workflow->update([
            'status' => 'pending',
            'column_mapping' => $mapping,
            'custom_prompt' => $runConfig['custom_prompt'] ?? null,
            'verification_toggles' => $runConfig['verification_toggles'] ?? null,
            'distribution_users' => $runConfig['distribution_users'] ?? null,
            'distribution_cursor' => 0,
            'ingestion_row_offset' => 0,
            'ingestion_complete' => false,
            'total_leads' => 0,
            'processed_leads' => 0,
            'failed_leads' => 0,
            'error_message' => null,
        ]);

        $workflow->loadMissing('workspace');
        $this->syncService->record(
            $workflow->workspace,
            'workflow.queued',
            'workflow',
            $workflow->id,
            ['status' => 'pending', 'name' => $workflow->name]
        );

        ProcessWorkflowJob::dispatch($workflow->id, $workflow->file_path);
    }

    public function activateStoredPipeline(Workflow $workflow): void
    {
        if (! $workflow->isStoreOnly()) {
            throw ValidationException::withMessages([
                'workflow' => 'Only stored imports can be activated.',
            ]);
        }

        if ($workflow->isProcessing()) {
            throw ValidationException::withMessages([
                'workflow' => 'This pipeline is already processing.',
            ]);
        }

        if ($workflow->leads()->count() === 0) {
            throw ValidationException::withMessages([
                'workflow' => 'No leads found to activate.',
            ]);
        }

        $workflow->update([
            'processing_mode' => 'full_pipeline',
            'status' => 'extracting',
            'processed_leads' => 0,
            'failed_leads' => 0,
            'distribution_cursor' => 0,
        ]);

        $workflow->leads()->update([
            'status' => 'pending',
            'pipeline_phase' => 'imported',
            'import_mode' => 'pipeline',
            'assigned_user_id' => null,
            'assigned_setter_id' => null,
            'assigned_closer_id' => null,
            'setter_status' => null,
            'closer_status' => null,
        ]);

        $this->dispatchPendingLeadJobs($workflow);

        $workflow->loadMissing('workspace');
        $this->syncService->record(
            $workflow->workspace,
            'workflow.activated',
            'workflow',
            $workflow->id,
            ['name' => $workflow->name]
        );
    }

    public function pauseProcessing(Workflow $workflow): void
    {
        if (! $workflow->isProcessing()) {
            throw ValidationException::withMessages([
                'workflow' => 'This pipeline is not currently running.',
            ]);
        }

        $workflow->loadMissing('workspace');
        $workspace = $workflow->workspace;

        $workflow->update(['status' => 'paused']);

        WorkflowLead::where('workflow_id', $workflow->id)
            ->where('status', 'extracting')
            ->update(['status' => 'pending']);

        $importedCount = WorkflowLead::where('workflow_id', $workflow->id)->count();
        if ($importedCount > 0) {
            $workflow->update(['total_leads' => max($workflow->total_leads, $importedCount)]);
        }

        $this->syncService->record(
            $workspace,
            'workflow.paused',
            'workflow',
            $workflow->id,
            [
                'name' => $workflow->name,
                'status' => 'paused',
                'processed_leads' => $workflow->processed_leads,
                'total_leads' => $workflow->total_leads,
            ]
        );
    }

    public function resumeProcessing(Workflow $workflow): void
    {
        if (! $workflow->isPaused()) {
            throw ValidationException::withMessages([
                'workflow' => 'This pipeline is not paused.',
            ]);
        }

        $workflow->loadMissing('workspace');
        $workspace = $workflow->workspace;

        if ($workflow->total_leads > 0
            && ($workflow->processed_leads + $workflow->failed_leads) >= $workflow->total_leads) {
            $workflow->update(['status' => 'completed']);

            $this->syncService->record(
                $workspace,
                'workflow.completed',
                'workflow',
                $workflow->id,
                [
                    'name' => $workflow->name,
                    'processed_leads' => $workflow->processed_leads,
                    'failed_leads' => $workflow->failed_leads,
                ]
            );

            return;
        }

        $pendingCount = $workflow->leads()->where('status', 'pending')->count();
        $ingestionIncomplete = ! $workflow->ingestion_complete;

        $workflow->update([
            'status' => ($pendingCount > 0 || $ingestionIncomplete || $workflow->leads()->exists())
                ? 'extracting'
                : 'pending',
        ]);

        if ($ingestionIncomplete && $workflow->file_path) {
            ProcessWorkflowJob::dispatch($workflow->id, $workflow->file_path);
        } elseif ($pendingCount > 0) {
            $this->dispatchPendingLeadJobs($workflow);
        } elseif (! $workflow->leads()->exists() && $workflow->file_path) {
            ProcessWorkflowJob::dispatch($workflow->id, $workflow->file_path);
        }

        $this->syncService->record(
            $workspace,
            'workflow.resumed',
            'workflow',
            $workflow->id,
            [
                'name' => $workflow->name,
                'status' => $workflow->status,
                'processed_leads' => $workflow->processed_leads,
                'total_leads' => $workflow->total_leads,
            ]
        );
    }

    public function dispatchPendingLeadJobs(Workflow $workflow): void
    {
        $workflow->leads()
            ->where('status', 'pending')
            ->orderBy('row_number')
            ->pluck('id')
            ->each(fn (int $leadId) => ProcessLeadJob::dispatch($leadId, $workflow->custom_prompt));
    }

    public function delete(Workflow $workflow): void
    {
        $workflow->loadMissing('workspace');
        $workspace = $workflow->workspace;
        $workflowId = $workflow->id;
        $workflowName = $workflow->name;
        $filePath = $workflow->file_path;

        DB::transaction(function () use ($workflow, $filePath) {
            $workflow->leads()->delete();

            if ($filePath && Storage::disk('local')->exists($filePath)) {
                Storage::disk('local')->delete($filePath);
            }

            $workflow->delete();
        });

        if ($workspace) {
            $this->syncService->record(
                $workspace,
                'workflow.deleted',
                'workflow',
                $workflowId,
                ['name' => $workflowName]
            );
        }
    }
}
