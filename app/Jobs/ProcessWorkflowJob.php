<?php

namespace App\Jobs;

use App\Models\Workflow;
use App\Models\WorkflowLead;
use App\Services\Pipeline\LeadImportDedupService;
use App\Services\Pipeline\LeadSegmentationService;
use App\Services\Workspace\WorkspaceSyncService;
use App\Support\LeadStageSync;
use App\Support\SpreadsheetHeaderDetector;
use App\Support\SpreadsheetText;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv as CsvReader;

class ProcessWorkflowJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;
    public int $timeout = 3600;

    public function __construct(
        public int $workflowId,
        public string $filePath
    ) {
        $this->onQueue('ingest');
    }

    public function handle(
        \App\Services\Workflow\WorkflowDataFormatter $formatter,
        LeadImportDedupService $dedup,
        LeadSegmentationService $segmentation,
        WorkspaceSyncService $syncService,
    ): void {
        set_time_limit(3600);
        @ini_set('memory_limit', '1024M');

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
            if ($reader instanceof CsvReader) {
                $reader->setInputEncoding('UTF-8');
            }

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
                    $rowData[] = SpreadsheetText::normalize($cell->getValue());
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

            $detected = SpreadsheetHeaderDetector::detect($rows);
            $headerIndex = (int) $detected['index'];
            $headers = array_map(
                fn ($val) => SpreadsheetText::normalize($val),
                $detected['headers']
            );

            $dataRows = array_slice($rows, $headerIndex + 1);
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
                    $rowNumbers[] = $headerIndex + $offset + $rowsProcessed + 2;
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
                    $ownerName = trim((string) ($mappedData['owner_name'] ?? ''));
                    if ($ownerName !== '' && \App\Support\LeadContactDisplay::looksLikePhoneNumber($ownerName)) {
                        $ownerName = '';
                    }
                    if ($ownerName === '') {
                        $ownerName = \App\Support\LeadContactDisplay::for(new \App\Models\WorkflowLead([
                            'raw_row' => $rawRow,
                        ]))['owner'] ?? '';
                    }

                    $resolvedState = \App\Support\UsAreaCodeState::resolve(
                        $mappedData['state'] ?? null,
                        $phoneFields['input_phone'] ?? ($phoneFields['normalized_phone'] ?? null)
                    );

                    $lead = WorkflowLead::create([
                        'workflow_id' => $workflow->id,
                        'campaign_id' => $campaignId,
                        'lead_list_id' => $workflow->lead_list_id,
                        'import_mode' => $workflow->isImportOnly() ? 'stored' : 'pipeline',
                        'pipeline_phase' => 'imported',
                        'stage' => LeadStageSync::forImport(),
                        'status' => 'imported',
                        'row_number' => $spreadsheetRowNumber,
                        'business_name' => $this->truncateField($mappedData['business_name'] ?? '', 255),
                        'address' => $this->truncateField($mappedData['address'] ?? null, 255),
                        'city' => $this->truncateField($mappedData['city'] ?? null, 255),
                        'state' => $this->truncateField($resolvedState, 255),
                        'zip_code' => $this->truncateField($mappedData['zip_code'] ?? null, 32),
                        'country' => $this->truncateField($mappedData['country'] ?? null, 255),
                        'website' => $this->truncateField($mappedData['website'] ?? null, 2000),
                        'input_phone' => $phoneFields['input_phone'],
                        'normalized_phone' => $phoneFields['normalized_phone'],
                        'input_email' => $this->truncateField($mappedData['input_email'] ?? null, 255),
                        'owner_name' => $ownerName !== '' ? $this->truncateField($ownerName, 255) : null,
                        'raw_row' => $rawRow,
                        'tags' => is_array($workflow->import_tags) && $workflow->import_tags !== []
                            ? $workflow->import_tags
                            : null,
                        'segment' => filled($workflow->import_segment)
                            ? $this->truncateField($workflow->import_segment, 120)
                            : null,
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
                $workflow->update([
                    'status' => 'completed',
                    'processed_leads' => $totalLeads,
                ]);
                $syncService->record($workspace, 'workflow.completed', 'workflow', $workflow->id, [
                    'processed_leads' => $totalLeads,
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

    protected function truncateField(mixed $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max) : $text;
    }
}
