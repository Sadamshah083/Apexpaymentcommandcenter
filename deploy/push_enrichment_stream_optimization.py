#!/usr/bin/env python3
"""Deploy lightweight workflow progress streaming and resilient enrichment retries."""

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

from deploy._ssh import REMOTE_APP, connect, sudo_run, sudo_run_batch, upload_files

FILES = [
    "app/Http/Controllers/WorkspaceSyncController.php",
    "app/Services/Workspace/WorkspaceSyncService.php",
    "app/Services/Workflow/WorkflowExtractor.php",
    "app/Jobs/ProcessLeadJob.php",
    "config/workflow_enrichment.php",
    "resources/views/workflows/show.blade.php",
]

REQUEUE = r"""<?php
require '/var/www/apexone/vendor/autoload.php';
$app = require '/var/www/apexone/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Jobs\ProcessLeadJob;
use App\Models\Workflow;
use App\Models\WorkflowLead;

$workflow = Workflow::find(18);
if (! $workflow) {
    echo "workflow 18 missing\n";
    exit;
}

$transient = WorkflowLead::query()
    ->where('workflow_id', $workflow->id)
    ->where('status', 'failed')
    ->where(function ($query) {
        foreach ([
            'rate limit',
            'Provider returned error',
            'operation was aborted',
            'timeout',
            'temporarily throttled',
        ] as $needle) {
            $query->orWhere('error_message', 'like', '%'.$needle.'%');
        }
    })
    ->pluck('id');

if ($transient->isEmpty()) {
    echo "no transient failures to requeue\n";
    exit;
}

WorkflowLead::whereIn('id', $transient)->update([
    'status' => 'imported',
    'error_message' => null,
]);
$remainingFailed = WorkflowLead::where('workflow_id', $workflow->id)
    ->where('status', 'failed')
    ->count();
$workflow->update([
    'status' => 'extracting',
    'failed_leads' => $remainingFailed,
]);

foreach ($transient as $leadId) {
    ProcessLeadJob::dispatch((int) $leadId, $workflow->custom_prompt);
}

echo 'requeued=' . $transient->count() . ' remaining_failed=' . $remainingFailed . PHP_EOL;
"""

VERIFY = r"""<?php
require '/var/www/apexone/vendor/autoload.php';
$app = require '/var/www/apexone/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$workflow = App\Models\Workflow::find(18);
$user = App\Models\User::find($workflow?->workspace?->admin_id);
if (! $workflow || ! $user) {
    echo "verify fixtures unavailable\n";
    exit;
}
$service = app(App\Services\Workspace\WorkspaceSyncService::class);
$payload = $service->poll($workflow->workspace, $user, null, null, 18, null, 'progress');
echo 'progress_bytes=' . strlen(json_encode($payload))
    . ' workflows=' . count($payload['workflows'] ?? [])
    . ' leads=' . count($payload['leads'] ?? []) . PHP_EOL;
"""


def put_temp(ssh, path: str, contents: str) -> None:
    sftp = ssh.open_sftp()
    try:
        with sftp.file(path, "w") as handle:
            handle.write(contents)
    finally:
        sftp.close()


def main() -> None:
    ssh = connect()
    try:
        upload_files(ssh, [(ROOT / rel, rel) for rel in FILES])
        put_temp(ssh, "/tmp/apex_requeue_transient.php", REQUEUE)
        put_temp(ssh, "/tmp/apex_verify_progress.php", VERIFY)

        lint = " && ".join(f"php -l {REMOTE_APP}/{rel}" for rel in FILES if rel.endswith(".php"))
        print(sudo_run(ssh, lint, check=True))
        print(sudo_run_batch(ssh, [
            f"cd {REMOTE_APP} && php artisan config:clear",
            f"cd {REMOTE_APP} && php artisan view:clear",
            f"cd {REMOTE_APP} && php artisan cache:clear",
            "systemctl restart apexone-queue.service",
            "systemctl reload php8.3-fpm 2>/dev/null || true",
        ], check=False))
        time.sleep(2)
        print(sudo_run(ssh, "php /tmp/apex_requeue_transient.php", check=False))
        print(sudo_run(ssh, "php /tmp/apex_verify_progress.php", check=False))
        print(sudo_run(ssh, "systemctl is-active apexone-queue.service && pgrep -c -f 'artisan queue:work'", check=False))
    finally:
        ssh.close()


if __name__ == "__main__":
    main()
