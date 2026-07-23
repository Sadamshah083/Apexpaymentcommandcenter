#!/usr/bin/env python3
"""Deploy Find recording reliability fixes + backfill missing files."""

from __future__ import annotations

import os
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
os.environ.setdefault("DEPLOY_PASSWORD", "balitech1")

import deploy._ssh as m

m.HOST = "203.215.161.236"
m.USER = "ateg"
m.PASSWORD = "balitech1"
m.REMOTE_APP = "/var/www/apexone"

from deploy._ssh import REMOTE_APP, connect, sudo_run_batch, upload_files

FILES = [
    "app/Services/Communications/CommunicationsCallRecordingService.php",
    "app/Services/Integrations/ZoomApiService.php",
    "app/Http/Controllers/CommunicationsHubController.php",
    "resources/views/communications/agent-status/partials/panel.blade.php",
]

BACKFILL = r"""<?php
require '/var/www/apexone/vendor/autoload.php';
$app = require '/var/www/apexone/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\CommunicationCallLog;
use App\Services\Communications\AgentStatusReportService;
use App\Services\Communications\CommunicationsCallRecordingService;

$svc = app(CommunicationsCallRecordingService::class);
$from = now()->subDays(7)->startOfDay();

$logs = CommunicationCallLog::query()
    ->where('created_at', '>=', $from)
    ->whereNotNull('morpheus_call_uuid')
    ->where('morpheus_call_uuid', '!=', '')
    ->where(function ($q) {
        $q->whereNull('recording_file_id')
          ->orWhere('recording_file_id', '')
          ->orWhereIn('recording_status', ['none', 'pending', 'unavailable', '']);
    })
    ->where(function ($q) {
        $q->where('duration_sec', '>', 0)
          ->orWhereNotNull('morpheus_call_uuid');
    })
    ->orderByDesc('id')
    ->limit(150)
    ->get();

$ready = 0;
$pending = 0;
$unavailable = 0;
$workspaces = [];

foreach ($logs as $log) {
    $meta = $log->meta ?? [];
    $meta['recording_attempt'] = 0;
    unset($meta['recording_skip_reason']);
    $log->update(['recording_status' => 'pending', 'meta' => $meta]);
    $fresh = $svc->resolveAndPersist($log->fresh(), 1);
    if ($fresh->recording_status === 'ready' && filled($fresh->recording_file_id)) {
        $ready++;
    } elseif ($fresh->recording_status === 'unavailable') {
        $unavailable++;
    } else {
        $pending++;
    }
    if ($fresh->workspace_id) {
        $workspaces[(int) $fresh->workspace_id] = true;
    }
}

foreach (array_keys($workspaces) as $wid) {
    AgentStatusReportService::forgetCachesForWorkspace((int) $wid);
}

echo "scanned={$logs->count()} ready={$ready} pending={$pending} unavailable={$unavailable}\n";
"""


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    print(f"Uploading {len(pairs)} files...")
    upload_files(ssh, pairs, app_root=REMOTE_APP)

    local = ROOT / "deploy" / "_backfill_rec_tmp.php"
    local.write_text(BACKFILL, encoding="utf-8")
    upload_files(ssh, [(local, "storage/app/_backfill_rec.php")], app_root=REMOTE_APP)

    print(sudo_run_batch(ssh, [
        f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan cache:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php storage/app/_backfill_rec.php",
        f"rm -f {REMOTE_APP}/storage/app/_backfill_rec.php",
    ]))
    local.unlink(missing_ok=True)
    ssh.close()
    print("Find recording fix deployed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
