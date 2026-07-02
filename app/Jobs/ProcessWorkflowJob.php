<?php

namespace App\Jobs;

use App\Models\Workflow;
use App\Models\WorkflowLead;
use App\Services\Pipeline\LeadImportDedupService;
use App\Services\Pipeline\LeadSegmentationService;
use App\Services\Workspace\WorkspaceSyncService;
use App\Support\SpreadsheetChunkReadFilter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ProcessWorkflowJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

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
        $workflow = Workflow::find($this->workflowId);
        if (! $workflow) {
            return;
        }

        if ($workflow->isPaused()) {
            return;
        }

        if ($workflow->ingestion_complete) {
            if ($workflow->runsEnrichmentOnImport()) {
                $this->dispatchEnrichmentJobs($workflow);
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

        $chunkSize = max(50, (int) config('workflow.import_chunk_size', 250));

        try {
            $workflow->update(['status' => 'extracting']);

            $headers = $this->readHeaderRow($fullPath, $workflow->selected_sheet);
            if ($headers === []) {
                $workflow->update([
                    'status' => 'failed',
                    'error_message' => 'No header row found in sheet.',
                ]);

                return;
            }

            $mapping = $workflow->column_mapping ?? [];
            $offset = (int) ($workflow->ingestion_row_offset ?? 0);
            $dataStartRow = 2 + $offset;
            $dataEndRow = $dataStartRow + $chunkSize - 1;

            $dataRows = $this->readRowRange($fullPath, $workflow->selected_sheet, $dataStartRow, $dataEndRow);
            if ($dataRows === []) {
                $this->finalizeIngestion($workflow, $workspace, $syncService);

                return;
            }

            $existingRowNumbers = WorkflowLead::where('workflow_id', $workflow->id)
                ->pluck('row_number')
                ->flip();

            $batchPhones = [];
            $discardedDuplicates = (int) ($workflow->discarded_duplicates ?? 0);
            $importTagIds = $workflow->import_tag_ids ?? [];
            $now = now()->toDateTimeString();
            $rowsProcessed = 0;
            $insertPayloads = [];
            $insertedRowNumbers = [];
            $totalLeadsInserted = (int) $workflow->total_leads;

            $rawRowsBatch = [];
            $rowNumbers = [];

            foreach ($dataRows as $rowData) {
                $rawRow = [];
                foreach ($headers as $index => $header) {
                    if ($header !== '') {
                        $rawRow[$header] = $rowData[$index] ?? null;
                    }
                }
                $rawRowsBatch[] = $rawRow;
                $rowNumbers[] = $dataStartRow + $rowsProcessed;
                $rowsProcessed++;
            }

            $mappedDataBatch = $formatter->formatRowsBatch($rawRowsBatch, $mapping);

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

                $insertPayloads[] = [
                    'workflow_id' => $workflow->id,
                    'lead_list_id' => $workflow->lead_list_id,
                    'import_mode' => $workflow->isImportOnly() ? 'stored' : 'pipeline',
                    'pipeline_phase' => 'imported',
                    'status' => 'imported',
                    'row_number' => $spreadsheetRowNumber,
                    'business_name' => $mappedData['business_name'] ?? '',
                    'owner_name' => $this->cleanImportValue($mappedData['owner_name'] ?? null),
                    'address' => $mappedData['address'] ?? null,
                    'city' => $mappedData['city'] ?? null,
                    'state' => $mappedData['state'] ?? null,
                    'zip_code' => $mappedData['zip_code'] ?? null,
                    'country' => $mappedData['country'] ?? null,
                    'website' => $mappedData['website'] ?? null,
                    'input_phone' => $phoneFields['input_phone'],
                    'normalized_phone' => $phoneFields['normalized_phone'],
                    'input_email' => $mappedData['input_email'] ?? null,
                    'raw_row' => json_encode($rawRow),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $insertedRowNumbers[] = $spreadsheetRowNumber;
                $existingRowNumbers->put($spreadsheetRowNumber, true);
                $totalLeadsInserted++;
            }

            if ($insertPayloads !== []) {
                foreach (array_chunk($insertPayloads, 100) as $chunk) {
                    DB::table('workflow_leads')->insert($chunk);
                }

                if ($importTagIds !== []) {
                    $insertedLeadIds = WorkflowLead::query()
                        ->where('workflow_id', $workflow->id)
                        ->whereIn('row_number', $insertedRowNumbers)
                        ->pluck('id')
                        ->all();
                    $segmentation->attachTagsToLeads($insertedLeadIds, $importTagIds);
                }
            }

            $newOffset = $offset + $rowsProcessed;
            $hasMoreRows = count($dataRows) === $chunkSize;

            $workflow->update([
                'ingestion_row_offset' => $newOffset,
                'total_leads' => $totalLeadsInserted,
                'discarded_duplicates' => $discardedDuplicates,
                'ingestion_complete' => ! $hasMoreRows,
            ]);

            $syncService->record($workspace, 'workflow.import_progress', 'workflow', $workflow->id, [
                'name' => $workflow->name,
                'total_leads' => $totalLeadsInserted,
                'offset' => $newOffset,
            ]);

            if ($hasMoreRows) {
                self::dispatch($workflow->id, $this->filePath)->delay(now()->addSecond());

                return;
            }

            $this->finalizeIngestion($workflow->fresh(), $workspace, $syncService, $dedup);
        } catch (\Throwable $e) {
            Log::error('Workflow processing error: '.$e->getMessage(), [
                'workflow_id' => $workflow->id,
                'trace' => $e->getTraceAsString(),
            ]);
            $workflow?->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
        }
    }

    protected function finalizeIngestion(
        Workflow $workflow,
        $workspace,
        WorkspaceSyncService $syncService,
    ): void {

        $totalLeads = WorkflowLead::where('workflow_id', $workflow->id)->count();
        $workflow->update([
            'ingestion_complete' => true,
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

        if ($workflow->isImportOnly()) {
            $workflow->update(['status' => 'completed']);
            $syncService->record($workspace, 'workflow.completed', 'workflow', $workflow->id, [
                'processed_leads' => 0,
                'failed_leads' => 0,
                'import_only' => true,
            ]);

            return;
        }

        if ($workflow->runsEnrichmentOnImport()) {
            $this->dispatchEnrichmentJobs($workflow);
        } else {
            $workflow->update(['status' => 'completed']);
        }
    }

    protected function dispatchEnrichmentJobs(Workflow $workflow): void
    {
        DispatchWorkflowLeadEnrichmentJob::dispatch($workflow->id);
    }

    /**
     * @return array<int, string>
     */
    protected function readHeaderRow(string $fullPath, ?string $sheetName): array
    {
        $rows = $this->readRowRange($fullPath, $sheetName, 1, 1);
        if ($rows === []) {
            return [];
        }

        return array_map(
            fn ($val) => $val !== null ? trim((string) $val) : '',
            $rows[0]
        );
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    protected function readRowRange(string $fullPath, ?string $sheetName, int $startRow, int $endRow): array
    {
        $reader = IOFactory::createReaderForFile($fullPath);
        $reader->setReadDataOnly(true);
        $reader->setReadFilter(new SpreadsheetChunkReadFilter($startRow, $endRow));

        if ($sheetName) {
            $reader->setLoadSheetsOnly([$sheetName]);
        }

        $spreadsheet = $reader->load($fullPath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = [];

        foreach ($sheet->getRowIterator($startRow, $endRow) as $row) {
            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false);
            $rowData = [];
            foreach ($cellIterator as $cell) {
                $rowData[] = $cell->getValue();
            }
            if ($this->rowHasContent($rowData)) {
                $rows[] = $rowData;
            }
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return $rows;
    }

    /**
     * @param  array<int, mixed>  $rowData
     */
    protected function rowHasContent(array $rowData): bool
    {
        foreach ($rowData as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return true;
            }
        }

        return false;
    }

    protected function cleanImportValue(mixed $value): ?string
    {
        if ($value === null || ! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '' || strtolower($value) === 'none found') {
            return null;
        }

        return $value;
    }
}
