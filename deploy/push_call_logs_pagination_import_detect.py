#!/usr/bin/env python3
"""Deploy call-log pagination/cache + headerless import auto-detect."""

from __future__ import annotations

import os
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
os.environ.setdefault("DEPLOY_PASSWORD", "balitech1")

import deploy._ssh as ssh_mod

ssh_mod.HOST = "203.215.161.236"
ssh_mod.USER = "ateg"
ssh_mod.PASSWORD = "balitech1"
ssh_mod.REMOTE_APP = "/var/www/apexone"

from deploy._ssh import REMOTE_APP, connect, sudo_run, sudo_run_batch, upload_files

FILES = [
    "app/Support/SpreadsheetHeaderDetector.php",
    "app/Support/LeadContactDisplay.php",
    "app/Services/Workflow/WorkflowAiMapper.php",
    "app/Services/Communications/AgentStatusReportService.php",
    "app/Http/Controllers/AgentStatusReportController.php",
    "resources/views/communications/agent-status/partials/panel.blade.php",
    "config/pagination.php",
    "tests/Unit/Support/SpreadsheetImportEncodingTest.php",
]

VERIFY_PHP = r"""<?php
require '/var/www/apexone/vendor/autoload.php';
$app = require '/var/www/apexone/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Support\SpreadsheetHeaderDetector;
use App\Services\Workflow\WorkflowAiMapper;
use App\Models\Workflow;
use App\Services\BusinessResearch\GeminiClient;
use Illuminate\Support\Facades\Storage;

$rows = [
    ['155', "Rick's Complete Auto Glass Service", '1', 'Mr. Rick Baker (Owner)', '', '', '', '(704) 701-7276', 'mobile'],
    ['156', 'Race City Autoworks', '3', 'Mr. Christopher Singer (Founder)', '', 'a@b.com', 'https://x.com', '(704) 450-3101', 'mobile'],
];
$d = SpreadsheetHeaderDetector::detect($rows);
echo 'detect index=' . $d['index'] . ' headers=' . json_encode($d['headers']) . PHP_EOL;
$mapper = new WorkflowAiMapper(app(GeminiClient::class));
$m = $mapper->heuristicMap($d['headers']);
echo 'mapping=' . json_encode($m) . PHP_EOL;

$wf = Workflow::query()->where('original_filename', 'like', '%Auto repairs%')->orderByDesc('id')->first();
if ($wf) {
    echo "wf id={$wf->id} status={$wf->status} err=" . substr((string) $wf->error_message, 0, 160) . PHP_EOL;
    echo 'old map=' . json_encode($wf->column_mapping) . PHP_EOL;
    if ($wf->file_path && Storage::disk('local')->exists($wf->file_path)) {
        $fast = $mapper->fastMap(Storage::disk('local')->path($wf->file_path), $wf->selected_sheet);
        echo 'fast headers=' . json_encode($fast['headers']) . PHP_EOL;
        echo 'fast mapping=' . json_encode($fast['mapping']) . PHP_EOL;
    }
}
"""

REMAP_PHP = r"""<?php
require '/var/www/apexone/vendor/autoload.php';
$app = require '/var/www/apexone/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Workflow;
use App\Jobs\ProcessWorkflowJob;
use App\Services\Workflow\WorkflowAiMapper;
use Illuminate\Support\Facades\Storage;

$mapper = app(WorkflowAiMapper::class);
$targets = Workflow::query()
    ->where(function ($q) {
        $q->where('original_filename', 'like', '%Auto repairs%')
          ->orWhere('name', 'like', '%Auto repairs%');
    })
    ->whereIn('status', ['failed', 'mapping'])
    ->get();

foreach ($targets as $wf) {
    if (! $wf->file_path || ! Storage::disk('local')->exists($wf->file_path)) {
        echo "skip {$wf->id} missing file\n";
        continue;
    }
    $fast = $mapper->fastMap(Storage::disk('local')->path($wf->file_path), $wf->selected_sheet);
    $mapping = $fast['mapping'] ?? [];
    if (empty($mapping['business_name'])) {
        echo "skip {$wf->id} still no business_name map=" . json_encode($mapping) . "\n";
        continue;
    }
    $wf->leads()->delete();
    $wf->update([
        'column_mapping' => $mapping,
        'status' => 'pending',
        'error_message' => null,
        'ingestion_complete' => false,
        'ingestion_row_offset' => 0,
        'total_leads' => 0,
        'enriched_leads' => 0,
        'failed_leads' => 0,
    ]);
    ProcessWorkflowJob::dispatch($wf->id, $wf->file_path);
    echo "queued {$wf->id} map=" . json_encode($mapping) . "\n";
}
"""


def main() -> None:
    pairs = [(ROOT / rel, rel.replace("\\", "/")) for rel in FILES]
    ssh = connect()
    try:
        upload_files(ssh, pairs)
        sftp = ssh.open_sftp()
        try:
            with sftp.file("/tmp/apex_verify_detect.php", "w") as f:
                f.write(VERIFY_PHP)
            with sftp.file("/tmp/apex_remap_repairs.php", "w") as f:
                f.write(REMAP_PHP)
        finally:
            sftp.close()

        sudo_run_batch(
            ssh,
            [
                f"cd {REMOTE_APP} && php artisan view:clear",
                f"cd {REMOTE_APP} && php artisan config:clear",
                f"cd {REMOTE_APP} && php artisan cache:clear",
                f"cd {REMOTE_APP} && php artisan opcache:clear 2>/dev/null || true",
                "systemctl reload php8.3-fpm 2>/dev/null || systemctl reload php-fpm 2>/dev/null || true",
            ],
            check=False,
        )
        print("--- verify detect ---")
        print(sudo_run(ssh, "php /tmp/apex_verify_detect.php", check=False))
        print("--- unit test ---")
        print(
            sudo_run(
                ssh,
                f"cd {REMOTE_APP} && php artisan test --filter=SpreadsheetImportEncoding 2>&1 | tail -50",
                check=False,
            )
        )
        print("--- remap failed Auto repairs ---")
        print(sudo_run(ssh, "php /tmp/apex_remap_repairs.php", check=False))
        print("Done.")
    finally:
        ssh.close()


if __name__ == "__main__":
    main()
