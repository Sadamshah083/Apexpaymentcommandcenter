#!/usr/bin/env python3
"""Probe recording Find across last 7 days on production."""

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

from deploy._ssh import connect, sudo_run

PHP = r"""<?php
require '/var/www/apexone/vendor/autoload.php';
$app = require '/var/www/apexone/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\CommunicationCallLog;
use App\Services\Communications\CommunicationsCallRecordingService;
use App\Services\Integrations\ZoomApiService;

echo 'now='.now()->toIso8601String().' tz='.config('app.timezone')."\n";
$from = now()->subDays(7);
$q = CommunicationCallLog::query()->where('created_at', '>=', $from);
echo 'week_total='.$q->count()."\n";
echo 'with_uuid='.(clone $q)->whereNotNull('morpheus_call_uuid')->where('morpheus_call_uuid', '!=', '')->count()."\n";
echo 'ready='.(clone $q)->where('recording_status', 'ready')->whereNotNull('recording_file_id')->count()."\n";
echo 'pending='.(clone $q)->where('recording_status', 'pending')->count()."\n";
echo 'unavailable='.(clone $q)->where('recording_status', 'unavailable')->count()."\n";
echo 'dur_gt0_no_file='.(clone $q)->where('duration_sec', '>', 0)->where(function ($x) {
    $x->whereNull('recording_file_id')->orWhere('recording_file_id', '');
})->count()."\n";
echo 'missing_uuid_dur='.(clone $q)->where('duration_sec', '>', 0)->where(function ($x) {
    $x->whereNull('morpheus_call_uuid')->orWhere('morpheus_call_uuid', '');
})->count()."\n";

$zoom = app(ZoomApiService::class);
$recent = $zoom->listRecordings(['per_page' => 8]);
foreach (array_slice($recent['recordings'] ?? [], 0, 5) as $r) {
    $uuid = (string) ($r['call_uuid'] ?? '');
    $id = (string) ($r['id'] ?? '');
    echo "rec id=$id uuid=$uuid start=".($r['start_time'] ?? '')."\n";
    if ($uuid !== '') {
        $byUuid = $zoom->listRecordings(['call_uuid' => $uuid, 'per_page' => 5]);
        echo '  list_by_uuid='.count($byUuid['recordings'] ?? []).' warnings='.json_encode($byUuid['warnings'] ?? [])."\n";
        $found = $zoom->findRecordingFileIdForCall($uuid);
        echo '  findRecordingFileId='.json_encode($found)."\n";
        $log = CommunicationCallLog::query()->where('morpheus_call_uuid', $uuid)->latest('id')->first();
        if ($log) {
            echo "  local_log id={$log->id} status={$log->recording_status} file=".($log->recording_file_id ?: '-')." dur={$log->duration_sec}\n";
        } else {
            echo "  local_log=NONE\n";
        }
    }
}

$cands = CommunicationCallLog::query()
    ->where('created_at', '>=', $from)
    ->where('duration_sec', '>', 0)
    ->orderByDesc('id')
    ->limit(8)
    ->get(['id', 'morpheus_call_uuid', 'duration_sec', 'recording_status', 'recording_file_id', 'to_phone', 'created_at', 'user_id']);
$svc = app(CommunicationsCallRecordingService::class);
foreach ($cands as $log) {
    echo "\n--- log {$log->id} uuid=".($log->morpheus_call_uuid ?: 'NONE')." dur={$log->duration_sec} st={$log->recording_status} file=".($log->recording_file_id ?: '-')." at={$log->created_at} ---\n";
    if (! filled($log->morpheus_call_uuid)) {
        continue;
    }
    if (filled($log->recording_file_id)) {
        continue;
    }
    $fresh = $svc->resolveAndPersist($log->fresh());
    echo "after status={$fresh->recording_status} file=".($fresh->recording_file_id ?: '-')."\n";
}
"""


def main() -> int:
    ssh = connect()
    try:
        sftp = ssh.open_sftp()
        with sftp.file("/tmp/apex_findrec2.php", "w") as f:
            f.write(PHP)
        sftp.close()
        print(sudo_run(ssh, "php /tmp/apex_findrec2.php", check=False))
    finally:
        ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
