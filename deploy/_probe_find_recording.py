#!/usr/bin/env python3
"""Probe call-log recording Find readiness on production."""

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

$today = now()->startOfDay();
$q = CommunicationCallLog::query()->where('created_at', '>=', $today);
echo "today_total=" . $q->count() . "\n";
echo "with_uuid=" . (clone $q)->whereNotNull('morpheus_call_uuid')->where('morpheus_call_uuid','!=','')->count() . "\n";
echo "ready_rec=" . (clone $q)->where('recording_status','ready')->whereNotNull('recording_file_id')->count() . "\n";
echo "pending_rec=" . (clone $q)->where('recording_status','pending')->count() . "\n";
echo "none_rec=" . (clone $q)->where(function($x){ $x->whereNull('recording_status')->orWhereIn('recording_status',['none','','unavailable']); })->count() . "\n";
echo "dur_gt0_no_rec=" . (clone $q)->where('duration_sec','>',0)->where(function($x){ $x->whereNull('recording_file_id')->orWhere('recording_file_id',''); })->count() . "\n";

$statusBreakdown = CommunicationCallLog::query()
    ->where('created_at', '>=', $today)
    ->selectRaw("COALESCE(NULLIF(TRIM(disposition), ''), NULLIF(TRIM(status), ''), 'Unknown') as sk, COUNT(*) c")
    ->groupBy('sk')
    ->orderByDesc('c')
    ->pluck('c','sk');
foreach ($statusBreakdown as $k=>$c) echo "status[$k]=$c\n";

$candidates = CommunicationCallLog::query()
    ->where('created_at', '>=', $today)
    ->where('duration_sec', '>', 0)
    ->where(function ($x) {
        $x->whereNull('recording_file_id')->orWhere('recording_file_id', '');
    })
    ->whereNotNull('morpheus_call_uuid')
    ->where('morpheus_call_uuid', '!=', '')
    ->orderByDesc('id')
    ->limit(5)
    ->get(['id','morpheus_call_uuid','duration_sec','recording_status','to_phone','disposition','status']);

$svc = app(CommunicationsCallRecordingService::class);
$zoom = app(ZoomApiService::class);

foreach ($candidates as $log) {
    echo "\n--- log {$log->id} uuid={$log->morpheus_call_uuid} dur={$log->duration_sec} rec_status={$log->recording_status} phone={$log->to_phone} ---\n";
    $snap = $zoom->getCall($log->morpheus_call_uuid);
    echo "getCall_has_recording=" . json_encode(data_get($snap, 'has_recording')) . " billsec=" . json_encode(data_get($snap, 'billsec') ?? data_get($snap, 'duration')) . "\n";
    $listed = $zoom->listRecordings(['call_uuid' => $log->morpheus_call_uuid, 'per_page' => 5]);
    echo "list_by_uuid_count=" . count($listed['recordings'] ?? []) . " warnings=" . json_encode($listed['warnings'] ?? []) . "\n";
    if (!empty($listed['recordings'][0])) {
        echo "first_rec=" . json_encode(array_intersect_key($listed['recordings'][0], array_flip(['id','call_uuid','uuid','created_at','direction','from','to']))) . "\n";
    }
    $before = $log->recording_file_id;
    $fresh = $svc->resolveAndPersist($log->fresh());
    echo "after_resolve status={$fresh->recording_status} file_id={$fresh->recording_file_id}\n";
}

// Broad recent recordings sample
$recent = $zoom->listRecordings(['per_page' => 5]);
echo "\nrecent_recordings=" . count($recent['recordings'] ?? []) . "\n";
foreach (array_slice($recent['recordings'] ?? [], 0, 3) as $r) {
    echo "rec id=" . ($r['id'] ?? '?') . " keys=" . implode(',', array_keys($r)) . "\n";
}
"""


def main() -> int:
    ssh = connect()
    try:
        sftp = ssh.open_sftp()
        with sftp.file("/tmp/apex_findrec.php", "w") as f:
            f.write(PHP)
        sftp.close()
        print(sudo_run(ssh, "php /tmp/apex_findrec.php", check=False))
    finally:
        ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
