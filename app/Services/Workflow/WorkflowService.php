<?php

namespace App\Services\Workflow;

use App\Jobs\ProcessLeadJob;
use App\Jobs\ProcessWorkflowJob;
use App\Models\Workflow;
use App\Models\WorkflowLead;
use App\Models\Workspace;
use App\Services\Pipeline\CampaignService;
use App\Services\Pipeline\LeadSegmentationService;
use App\Services\Pipeline\PipelineLeadReleaseService;
use App\Services\Workspace\WorkspaceSyncService;
use App\Support\WorkflowAssignmentRoles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class WorkflowService
{
    public function __construct(
        protected WorkflowAiMapper $aiMapper,
        protected WorkspaceSyncService $syncService,
        protected WorkflowProviderStatusService $providerStatus,
        protected LeadSegmentationService $segmentation,
        protected CampaignService $campaigns,
        protected PipelineLeadReleaseService $releaseService,
    ) {}

    public function createFromUpload(
        Workspace $workspace,
        string $name,
        UploadedFile $file,
        string $processingMode = 'import_only',
        ?int $campaignId = null,
    ): Workflow {
        $storedPath = $file->store('workflows');
        $sheets = [];
        $extension = strtolower((string) $file->getClientOriginalExtension());

        // CSV/TXT are single-sheet — skip PhpSpreadsheet sheet discovery for faster uploads.
        if (! in_array($extension, ['csv', 'txt'], true)) {
            try {
                $sheets = $this->aiMapper->getFileSheets(Storage::disk('local')->path($storedPath));
            } catch (\Throwable $e) {
                Log::debug('Workflow upload has no spreadsheet sheets', [
                    'workspace_id' => $workspace->id,
                    'filename' => $file->getClientOriginalName(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return Workflow::create([
            'workspace_id' => $workspace->id,
            'campaign_id' => $campaignId,
            'name' => $name,
            'processing_mode' => $this->normalizeProcessingMode($processingMode),
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
            // Fast heuristic map on upload — no Gemini round-trip (keeps import snappy).
            $autoMap = $this->aiMapper->fastMap(
                Storage::disk('local')->path($workflow->file_path),
                $workflow->selected_sheet
            );

            $resolvedMapping = $autoMap['mapping'] ?? [];

            if (! empty(array_filter($resolvedMapping))) {
                $workflow->update([
                    'column_mapping' => $resolvedMapping,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Workflow fast auto-mapping failed', [
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
        $workflow->load(['leadList', 'campaign']);
        $workflow->loadCount([
            'leads as assigned_leads_count' => fn ($query) => $query->whereNotNull('assigned_user_id'),
            'leads as pending_verification_count' => fn ($query) => $query->where('status', 'pending_verification'),
            'leads as imported_leads_count' => fn ($query) => $query->where('status', 'imported'),
            'leads as enriched_leads_count' => fn ($query) => $query->where('status', 'enriched'),
            'leads as ready_to_distribute_count' => fn ($query) => $query->readyToAssign(),
            'leads as ready_to_assign_count' => fn ($query) => $query->readyToAssign(),
        ]);

        $perPage = min(max((int) ($options['per_page'] ?? config('pagination.pipeline_leads_per_page', 25)), 5), 100);
        $pool = $options['pool'] ?? null;

        $leadsQuery = $workflow->leads()
            ->with(['campaign', 'leadList']);

        if ($pool === 'unassigned') {
            $leadsQuery->readyToAssign();
        }

        $leads = $leadsQuery
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
            'teamLeads' => WorkflowAssignmentRoles::setterTeamLeadsFor($workspace),
            'campaign' => $workflow->campaign,
            'campaigns' => $this->campaigns->listForWorkspace($workspace),
            'leadList' => $workflow->leadList,
            'enrichmentConfigured' => $this->providerStatus->isEnrichmentConfigured(),
            'enrichmentConfigMessage' => $this->providerStatus->configurationMessage(),
            'enrichmentStatus' => $this->providerStatus->getEnrichmentStatus(
                (bool) ($options['refresh_enrichment'] ?? false)
            ),
            'retryableFailedLeads' => $workflow->failed_leads,
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

        $runEnrichment = ! empty($runConfig['run_enrichment_on_import']);
        if ($runEnrichment && ! $this->providerStatus->isEnrichmentConfigured()) {
            throw ValidationException::withMessages([
                'enrichment' => $this->providerStatus->configurationMessage(),
            ]);
        }

        $processingMode = $runEnrichment ? 'import_and_enrich' : 'import_only';
        $autoAssign = ! empty($runConfig['auto_assign_setters']);

        $workflow->loadMissing('workspace');
        $list = $this->segmentation->createImportList(
            $workflow->workspace,
            Auth::user(),
            $workflow->name,
        );

        $workflow->leads()->delete();

        $workflow->update([
            'status' => 'pending',
            'processing_mode' => $processingMode,
            'lead_list_id' => $list->id,
            'auto_assign_setters' => $autoAssign,
            'column_mapping' => $mapping,
            'custom_prompt' => $runConfig['custom_prompt'] ?? null,
            'verification_toggles' => $runConfig['verification_toggles'] ?? null,
            'distribution_users' => $runConfig['distribution_users'] ?? null,
            'distribution_cursor' => 0,
            'ingestion_row_offset' => 0,
            'ingestion_complete' => false,
            'total_leads' => 0,
            'processed_leads' => 0,
            'enriched_leads' => 0,
            'failed_leads' => 0,
            'discarded_duplicates' => 0,
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

        if (! $this->providerStatus->isEnrichmentConfigured()) {
            throw ValidationException::withMessages([
                'enrichment' => $this->providerStatus->configurationMessage(),
            ]);
        }

        $workflow->update([
            'processing_mode' => 'import_and_enrich',
            'processed_leads' => 0,
            'enriched_leads' => 0,
            'failed_leads' => 0,
            'distribution_cursor' => 0,
            'auto_assign_setters' => false,
        ]);

        $workflow->leads()->update([
            'status' => 'imported',
            'pipeline_phase' => 'imported',
            'import_mode' => 'pipeline',
            'assigned_user_id' => null,
            'assigned_setter_id' => null,
            'assigned_closer_id' => null,
            'setter_status' => null,
            'closer_status' => null,
        ]);

        $this->startEnrichment($workflow);

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
            ->update(['status' => 'imported']);

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
            && ($workflow->enriched_leads + $workflow->failed_leads) >= $workflow->total_leads
            && $workflow->leads()->whereIn('status', ['imported', 'extracting'])->doesntExist()) {
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

        $importedCount = $workflow->leads()->where('status', 'imported')->count();
        $failedCount = $workflow->leads()->where('status', 'failed')->count();
        $ingestionIncomplete = ! $workflow->ingestion_complete;

        $workflow->update([
            'status' => ($importedCount > 0 || $failedCount > 0 || $ingestionIncomplete)
                ? 'extracting'
                : 'pending',
        ]);

        if ($ingestionIncomplete && $workflow->file_path) {
            ProcessWorkflowJob::dispatch($workflow->id, $workflow->file_path);
        } elseif ($importedCount > 0) {
            $this->startEnrichment($workflow);
        } elseif ($failedCount > 0) {
            $this->retryFailedLeads($workflow);
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

    public function startEnrichment(Workflow $workflow): void
    {
        if (! $this->providerStatus->isEnrichmentConfigured()) {
            throw ValidationException::withMessages([
                'enrichment' => $this->providerStatus->configurationMessage(),
            ]);
        }

        if (! $workflow->ingestion_complete) {
            throw ValidationException::withMessages([
                'workflow' => 'Import is still running. Wait for it to finish before starting enrichment.',
            ]);
        }

        if ($workflow->isProcessing() && $workflow->leads()->where('status', 'extracting')->exists()) {
            throw ValidationException::withMessages([
                'workflow' => 'Enrichment is already running.',
            ]);
        }

        $importedCount = $workflow->leads()->where('status', 'imported')->count();
        if ($importedCount === 0) {
            throw ValidationException::withMessages([
                'workflow' => 'No imported leads are waiting for enrichment.',
            ]);
        }

        $workflow->update([
            'status' => 'extracting',
            'processing_mode' => 'import_and_enrich',
        ]);

        $this->dispatchPendingLeadJobs($workflow);
    }

    /**
     * @param  array<int, int>|null  $distributionUsers
     */
    public function distributeToSetters(Workflow $workflow, ?array $distributionUsers = null): int
    {
        if ($distributionUsers !== null) {
            $workflow->update(['distribution_users' => $distributionUsers]);
        }

        $readyCount = $workflow->leads()->where('status', 'enriched')->whereNull('assigned_user_id')->count();
        if ($readyCount === 0) {
            throw ValidationException::withMessages([
                'workflow' => 'No enriched leads are ready for distribution.',
            ]);
        }

        $workflow->loadMissing('workspace');
        $actor = Auth::user();

        return $this->releaseService->distributeEnrichedLeads($workflow, $workflow->workspace, $actor);
    }

    public function dispatchPendingLeadJobs(Workflow $workflow): void
    {
        WorkflowLead::where('workflow_id', $workflow->id)
            ->where('status', 'imported')
            ->orderBy('row_number')
            ->pluck('id')
            ->each(fn (int $leadId) => ProcessLeadJob::dispatch($leadId, $workflow->custom_prompt));
    }

    public function retryFailedLeads(Workflow $workflow): void
    {
        if (! $this->providerStatus->isEnrichmentConfigured()) {
            throw ValidationException::withMessages([
                'enrichment' => $this->providerStatus->configurationMessage(),
            ]);
        }

        $failedCount = $workflow->leads()->where('status', 'failed')->count();
        if ($failedCount === 0) {
            throw ValidationException::withMessages([
                'workflow' => 'No failed leads to retry.',
            ]);
        }

        $workflow->loadMissing('workspace');

        WorkflowLead::where('workflow_id', $workflow->id)
            ->where('status', 'failed')
            ->update([
                'status' => 'imported',
                'error_message' => null,
            ]);

        $workflow->update([
            'status' => 'extracting',
            'failed_leads' => 0,
            'error_message' => null,
        ]);

        $this->dispatchPendingLeadJobs($workflow->fresh());

        $this->syncService->record(
            $workflow->workspace,
            'workflow.resumed',
            'workflow',
            $workflow->id,
            [
                'name' => $workflow->name,
                'status' => 'extracting',
                'retried_failed' => $failedCount,
            ]
        );
    }

    public function delete(Workflow $workflow): void
    {
        $workflow->loadMissing('workspace');
        $workspace = $workflow->workspace;
        $workflowId = $workflow->id;
        $workflowName = $workflow->name;
        $filePath = $workflow->file_path;

        // Stop enrichment immediately so queued ProcessLeadJob exits early.
        $workflow->forceFill([
            'status' => 'paused',
            'ingestion_complete' => true,
        ])->save();

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

    protected function normalizeProcessingMode(string $mode): string
    {
        return match ($mode) {
            'store_only', 'import_only' => 'import_only',
            'full_pipeline', 'import_and_enrich' => 'import_and_enrich',
            default => 'import_only',
        };
    }
}
