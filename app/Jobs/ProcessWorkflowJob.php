<?php

namespace App\Jobs;

use App\Models\Workflow;
use App\Models\WorkflowLead;
use App\Services\Pipeline\LeadImportDedupService;
use App\Services\Pipeline\LeadSegmentationService;
use App\Services\Workspace\WorkspaceSyncService;
use App\Support\LeadStageSync;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ProcessWorkflowJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;
    public int $timeout = 3600;

    public function __construct(
        public int $workflowId,
        public string $filePath
    ) {}

    public function handle(
        \App\Services\Workflow\WorkflowDataFormatter $formatter,
        LeadImportDedupService $dedup,
        LeadSegmentationService $segmentation,
        WorkspaceSyncService $syncService,
    ): void {
        set_time_limit(3600);

        $workflow = Workflow::find($this->workflowId);
        if (! $workflow) {
            return;
        }

        if ($workflow->isPaused()) {
            return;
        }

        if ($workflow->ingestion_complete) {
            if ($workflow->runsEnrichmentOnImport()) {
                $this->dispatchPendingLeadJobs($workflow);
            }

            return;
        }

        $workspace = $workflow->workspace;
        $fullPath = Storage::disk('local')->path($this->filePath);

        if (! is_file($fullPath)) {
            $workflow->update([
                'status' => 'failed',
                'error_message' => 'Uploaded spreadsheet file is missing.',
            ]);

            return;
        }

        try {
            $workflow->update(['status' => 'extracting']);

            $reader = IOFactory::createReaderForFile($fullPath);
            $reader->setReadDataOnly(true);

            if ($workflow->selected_sheet) {
                $reader->setLoadSheetsOnly([$workflow->selected_sheet]);
            }

            $spreadsheet = $reader->load($fullPath);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = [];

            foreach ($sheet->getRowIterator() as $row) {
                $cellIterator = $row->getCellIterator();
                $cellIterator->setIterateOnlyExistingCells(false);
                $rowData = [];
                foreach ($cellIterator as $cell) {
                    $rowData[] = $cell->getValue();
                }
                $rows[] = $rowData;
            }

            if (empty($rows)) {
                $workflow->update([
                    'status' => 'failed',
                    'error_message' => 'No rows found in sheet.',
                ]);

                return;
            }

            $headers = array_map(
                fn ($val) => $val !== null ? trim((string) $val) : '',
                $rows[0]
            );

            $dataRows = array_slice($rows, 1);
            $mapping = $workflow->column_mapping ?? [];
            $offset = (int) ($workflow->ingestion_row_offset ?? 0);
            $remainingRows = array_slice($dataRows, $offset);
            $existingRowNumbers = WorkflowLead::where('workflow_id', $workflow->id)
                ->pluck('row_number')
                ->flip();

            $batchPhones = [];
            $discardedDuplicates = (int) ($workflow->discarded_duplicates ?? 0);
            $campaignId = $workflow->campaign_id;

            Log::info("Processing workflow {$workflow->id} from row offset {$offset}");

            $chunks = array_chunk($remainingRows, 50);
            $rowsProcessed = 0;
            $totalLeadsInserted = WorkflowLead::where('workflow_id', $workflow->id)->count();
            $now = now();

            foreach ($chunks as $chunk) {
                $workflow->refresh();
                if (! $workflow || $workflow->isPaused()) {
                    return;
                }

                $rawRowsBatch = [];
                $rowNumbers = [];

                foreach ($chunk as $rowData) {
                    $rawRow = [];
                    foreach ($headers as $index => $header) {
                        if ($header !== '') {
                            $rawRow[$header] = $rowData[$index] ?? null;
                        }
                    }
                    $rawRowsBatch[] = $rawRow;
                    $rowNumbers[] = $offset + $rowsProcessed + 2;
                    $rowsProcessed++;
                }

                $mappedDataBatch = $formatter->formatRowsBatch($rawRowsBatch, $mapping);
                $insertedLeadIds = [];

                foreach ($rawRowsBatch as $i => $rawRow) {
                    $spreadsheetRowNumber = $rowNumbers[$i];
                    if ($existingRowNumbers->has($spreadsheetRowNumber)) {
                        continue;
                    }

                    $mappedData = $mappedDataBatch[$i] ?? [];
                    if (empty($mappedData['business_name'])) {
                        continue;
                    }

                    if ($dedup->shouldDiscard($workspace->id, $mappedData['input_phone'] ?? null, $batchPhones)) {
                        $discardedDuplicates++;

                        continue;
                    }

                    $phoneFields = $dedup->formatPhoneForStorage($mappedData['input_phone'] ?? null);

                    $lead = WorkflowLead::create([
                        'workflow_id' => $workflow->id,
                        'campaign_id' => $campaignId,
                        'lead_list_id' => $workflow->lead_list_id,
                        'import_mode' => $workflow->isImportOnly() ? 'stored' : 'pipeline',
                        'pipeline_phase' => 'imported',
                        'stage' => LeadStageSync::forImport(),
                        'status' => 'imported',
                        'row_number' => $spreadsheetRowNumber,
                        'business_name' => $mappedData['business_name'] ?? '',
                        'address' => $mappedData['address'] ?? null,
                        'city' => $mappedData['city'] ?? null,
                        'state' => $mappedData['state'] ?? null,
                        'zip_code' => $mappedData['zip_code'] ?? null,
                        'country' => $mappedData['country'] ?? null,
                        'website' => $mappedData['website'] ?? null,
                        'input_phone' => $phoneFields['input_phone'],
                        'normalized_phone' => $phoneFields['normalized_phone'],
                        'input_email' => $mappedData['input_email'] ?? null,
                        'raw_row' => $rawRow,
                    ]);

                    $insertedLeadIds[] = $lead->id;
                    $existingRowNumbers->put($spreadsheetRowNumber, true);
                    $totalLeadsInserted++;
                }

                $workflow->update([
                    'ingestion_row_offset' => $offset + $rowsProcessed,
                    'total_leads' => $totalLeadsInserted,
                    'discarded_duplicates' => $discardedDuplicates,
                ]);
            }

            $workflow->refresh();
            if (! $workflow || $workflow->isPaused()) {
                return;
            }

            $totalLeads = WorkflowLead::where('workflow_id', $workflow->id)->count();
            $workflow->update([
                'ingestion_complete' => true,
                'ingestion_row_offset' => count($dataRows),
                'total_leads' => $totalLeads,
                'discarded_duplicates' => $discardedDuplicates,
            ]);

            if ($totalLeads === 0) {
                $workflow->update(['status' => 'completed']);
                $syncService->record($workspace, 'workflow.completed', 'workflow', $workflow->id, [
                    'processed_leads' => 0,
                    'failed_leads' => 0,
                ]);

                return;
            }

            if ($workflow->isImportOnly()) {
                $workflow->update(['status' => 'completed']);
                $syncService->record($workspace, 'workflow.completed', 'workflow', $workflow->id, [
                    'processed_leads' => 0,
                    'failed_leads' => 0,
                    'import_only' => true,
                    'discarded_duplicates' => $discardedDuplicates,
                ]);

                return;
            }

            if ($workflow->runsEnrichmentOnImport()) {
                $this->dispatchPendingLeadJobs($workflow);
            } else {
                $workflow->update(['status' => 'completed']);
            }
        } catch (\Throwable $e) {
            Log::error('Workflow processing error: '.$e->getMessage());
            $workflow?->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
        }
    }

    protected function dispatchPendingLeadJobs(Workflow $workflow): void
    {
        WorkflowLead::where('workflow_id', $workflow->id)
            ->where('status', 'imported')
            ->orderBy('row_number')
            ->pluck('id')
            ->each(fn (int $leadId) => ProcessLeadJob::dispatch($leadId, $workflow->custom_prompt));

        $workflow->update(['status' => 'extracting']);
    }
}
