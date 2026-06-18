<?php

namespace App\Jobs;

use App\Models\Workflow;
use App\Models\WorkflowLead;
use App\Services\Workflow\WorkflowLeadDistributor;
use App\Services\Workspace\WorkspaceSyncService;
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
        WorkflowLeadDistributor $distributor,
        \App\Services\Workflow\WorkflowDataFormatter $formatter,
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
            $this->dispatchPendingLeadJobs($workflow);

            return;
        }

        if ($workflow->ingestion_row_offset > 0) {
            $this->dispatchPendingLeadJobs($workflow);
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

            Log::info("Processing workflow {$workflow->id} from row offset {$offset} with mapping: ".json_encode($mapping));

            $chunks = array_chunk($remainingRows, 50);
            $rowsProcessed = 0;

            foreach ($chunks as $chunk) {
                $workflow->refresh();
                if (! $workflow || $workflow->isPaused()) {
                    $this->syncIngestionProgress($workflow, $syncService, $workspace);

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

                foreach ($rawRowsBatch as $i => $rawRow) {
                    $workflow->refresh();
                    if (! $workflow || $workflow->isPaused()) {
                        $workflow?->update(['ingestion_row_offset' => $offset + $rowsProcessed]);
                        $this->syncIngestionProgress($workflow, $syncService, $workspace);

                        return;
                    }

                    $spreadsheetRowNumber = $rowNumbers[$i];
                    if ($existingRowNumbers->has($spreadsheetRowNumber)) {
                        continue;
                    }

                    $mappedData = $mappedDataBatch[$i] ?? [];
                    if (empty($mappedData['business_name'])) {
                        continue;
                    }

                    $lead = WorkflowLead::create([
                        'workflow_id' => $workflow->id,
                        'status' => 'pending',
                        'row_number' => $spreadsheetRowNumber,
                        'business_name' => $mappedData['business_name'] ?? '',
                        'address' => $mappedData['address'] ?? null,
                        'city' => $mappedData['city'] ?? null,
                        'state' => $mappedData['state'] ?? null,
                        'zip_code' => $mappedData['zip_code'] ?? null,
                        'country' => $mappedData['country'] ?? null,
                        'website' => $mappedData['website'] ?? null,
                        'input_phone' => $mappedData['input_phone'] ?? null,
                        'input_email' => $mappedData['input_email'] ?? null,
                        'raw_row' => $rawRow,
                    ]);

                    $existingRowNumbers->put($spreadsheetRowNumber, true);

                    $assignedUser = $distributor->assignNext($workspace, $lead, $workflow);
                    if ($assignedUser) {
                        $lead->refresh();
                    }

                    if (! $workflow->isPaused()) {
                        ProcessLeadJob::dispatch($lead->id, $workflow->custom_prompt);
                    }
                }

                $workflow->update([
                    'ingestion_row_offset' => $offset + $rowsProcessed,
                    'total_leads' => WorkflowLead::where('workflow_id', $workflow->id)->count(),
                ]);
            }

            $workflow->refresh();
            if (! $workflow || $workflow->isPaused()) {
                $this->syncIngestionProgress($workflow, $syncService, $workspace);

                return;
            }

            $totalLeads = WorkflowLead::where('workflow_id', $workflow->id)->count();
            $workflow->update([
                'ingestion_complete' => true,
                'ingestion_row_offset' => count($dataRows),
                'total_leads' => $totalLeads,
            ]);

            if ($totalLeads === 0) {
                $workflow->update(['status' => 'completed']);
                $syncService->record($workspace, 'workflow.completed', 'workflow', $workflow->id, [
                    'processed_leads' => 0,
                    'failed_leads' => 0,
                ]);

                return;
            }

            $this->dispatchPendingLeadJobs($workflow);
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
        $workflow->loadMissing('workspace');
        $workspace = $workflow->workspace;

        if ($workspace) {
            app(WorkflowLeadDistributor::class)->assignUnassignedPending($workspace, $workflow);
        }

        WorkflowLead::where('workflow_id', $workflow->id)
            ->where('status', 'pending')
            ->orderBy('row_number')
            ->pluck('id')
            ->each(fn (int $leadId) => ProcessLeadJob::dispatch($leadId, $workflow->custom_prompt));
    }

    protected function syncIngestionProgress(?Workflow $workflow, WorkspaceSyncService $syncService, $workspace): void
    {
        // Poll refreshes workflow cards from DB state; no toast event needed mid-import.
    }
}
