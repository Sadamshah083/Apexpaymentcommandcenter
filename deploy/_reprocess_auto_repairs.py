#!/usr/bin/env python3
"""Restart queue, reprocess Auto repairs imports, verify lead counts."""

from __future__ import annotations

import os
import sys
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
os.environ.setdefault("DEPLOY_PASSWORD", "balitech1")
import deploy._ssh as m

m.HOST = "203.215.161.236"
m.USER = "ateg"
m.PASSWORD = "balitech1"
m.REMOTE_APP = "/var/www/apexone"
from deploy._ssh import connect, sudo_run, sudo_run_batch

REPROCESS = r"""<?php
require '/var/www/apexone/vendor/autoload.php';
$app = require '/var/www/apexone/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Workflow;
use App\Jobs\ProcessWorkflowJob;
use App\Services\Workflow\WorkflowAiMapper;
use App\Support\SpreadsheetHeaderDetector;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;

$mapper = app(WorkflowAiMapper::class);
$wf = Workflow::find(20);
if (! $wf) { echo "missing\n"; exit; }
$path = Storage::disk('local')->path($wf->file_path);
$reader = IOFactory::createReaderForFile($path);
$reader->setReadDataOnly(true);
$sheet = $reader->load($path)->getActiveSheet();
$rows = [];
foreach ($sheet->getRowIterator(1, 5) as $row) {
    $it = $row->getCellIterator();
    $it->setIterateOnlyExistingCells(false);
    $r = [];
    foreach ($it as $cell) {
        $r[] = App\Support\SpreadsheetText::normalize($cell->getValue());
    }
    $rows[] = $r;
}
$detected = SpreadsheetHeaderDetector::detect($rows);
echo 'detect=' . json_encode($detected) . PHP_EOL;
$headerIndex = (int) $detected['index'];
$headers = $detected['headers'];
$dataRows = array_slice($rows, $headerIndex + 1);
echo 'dataRowCount=' . count($dataRows) . ' first=' . json_encode($dataRows[0] ?? null) . PHP_EOL;
$mapping = $mapper->heuristicMap($headers);
$raw = [];
foreach ($headers as $i => $h) {
    if ($h !== '') $raw[$h] = $dataRows[0][$i] ?? null;
}
echo 'raw0=' . json_encode($raw) . PHP_EOL;
$formatted = app(App\Services\Workflow\WorkflowDataFormatter::class)->formatRowsBatch([$raw], $mapping);
echo 'formatted0=' . json_encode($formatted[0] ?? null) . PHP_EOL;

foreach ([18, 19, 20] as $id) {
    $w = Workflow::find($id);
    if (! $w || ! $w->file_path || ! Storage::disk('local')->exists($w->file_path)) continue;
    $fast = $mapper->fastMap(Storage::disk('local')->path($w->file_path), $w->selected_sheet);
    $w->leads()->delete();
    $w->update([
        'column_mapping' => $fast['mapping'],
        'status' => 'pending',
        'error_message' => null,
        'ingestion_complete' => false,
        'ingestion_row_offset' => 0,
        'total_leads' => 0,
        'enriched_leads' => 0,
        'failed_leads' => 0,
    ]);
    // Run inline so we see errors immediately
    (new ProcessWorkflowJob($w->id, $w->file_path))->handle(
        app(App\Services\Workflow\WorkflowDataFormatter::class),
        app(App\Services\Pipeline\LeadImportDedupService::class),
        app(App\Services\Pipeline\LeadSegmentationService::class),
        app(App\Services\Workspace\WorkspaceSyncService::class),
    );
    $w->refresh();
    echo "done {$w->id} status={$w->status} total={$w->total_leads} leads=".$w->leads()->count()
        .' err='.substr((string)$w->error_message, 0, 160).PHP_EOL;
}
"""

ssh = connect()
try:
    print("--- restart queue ---")
    print(sudo_run_batch(ssh, [
        "systemctl restart apexone-queue.service",
        "systemctl is-active apexone-queue.service",
        "cd /var/www/apexone && php artisan queue:restart",
        "cd /var/www/apexone && php artisan opcache:clear 2>/dev/null || true",
    ], check=False))
    time.sleep(2)
    sftp = ssh.open_sftp()
    with sftp.file("/tmp/apex_reprocess_repairs.php", "w") as f:
        f.write(REPROCESS)
    sftp.close()
    print("--- reprocess inline ---")
    print(sudo_run(ssh, "php /tmp/apex_reprocess_repairs.php", check=False))
finally:
    ssh.close()
