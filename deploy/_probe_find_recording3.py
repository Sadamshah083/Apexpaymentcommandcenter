#!/usr/bin/env python3
"""Probe why Find fails for dur>0 calls without recording_file_id."""

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

$zoom = app(ZoomApiService::class);
$svc = app(CommunicationsCallRecordingService::class);

$missing = CommunicationCallLog::query()
    ->where('created_at', '>=', now()->subDays(7))
    ->where('duration_sec', '>', 0)
    ->where(function ($x) {
        $x->whereNull('recording_file_id')->orWhere('recording_file_id', '');
    })
    ->whereNotNull('morpheus_call_uuid')
    ->where('morpheus_call_uuid', '!=', '')
    ->orderByDesc('id')
    ->limit(12)
    ->get();

foreach ($missing as $log) {
    $uuid = (string) $log->morpheus_call_uuid;
    echo "\n=== log {$log->id} st={$log->recording_status} dur={$log->duration_sec} phone={$log->to_phone} at={$log->created_at} uuid={$uuid} ===\n";
    $snap = $zoom->getCall($uuid);
    echo 'getCall keys='.(is_array($snap) ? implode(',', array_keys($snap)) : 'null')."\n";
    echo 'has_recording='.json_encode(data_get($snap, 'has_recording')).' raw.has='.json_encode(data_get($snap, 'raw.has_recording')).' billsec='.json_encode(data_get($snap, 'billsec') ?? data_get($snap, 'duration'))."\n";
    $byUuid = $zoom->listRecordings(['call_uuid' => $uuid, 'per_page' => 10]);
    echo 'list_by_uuid='.count($byUuid['recordings'] ?? []).' warn='.json_encode($byUuid['warnings'] ?? [])."\n";

    // time-window search around call
    $from = optional($log->created_at)->copy()->subMinutes(5);
    $to = optional($log->created_at)->copy()->addMinutes(30);
    $window = $zoom->listRecordings([
        'from' => $from?->toIso8601String(),
        'to' => $to?->toIso8601String(),
        'per_page' => 50,
    ]);
    $recs = $window['recordings'] ?? [];
    echo 'window_count='.count($recs)."\n";
    $phoneDigits = preg_replace('/\D+/', '', (string) $log->to_phone);
    $matches = [];
    foreach ($recs as $r) {
        $raw = $r['raw'] ?? [];
        $dest = preg_replace('/\D+/', '', (string) ($raw['destination_number'] ?? $r['topic'] ?? ''));
        $cu = (string) ($r['call_uuid'] ?? '');
        $score = 0;
        if ($cu !== '' && $cu === $uuid) $score += 100;
        if ($phoneDigits !== '' && $dest !== '' && str_ends_with($dest, substr($phoneDigits, -7))) $score += 10;
        if ($score > 0) {
            $matches[] = ['score' => $score, 'id' => $r['id'] ?? '', 'uuid' => $cu, 'dest' => $dest, 'start' => $r['start_time'] ?? ''];
        }
    }
    usort($matches, fn ($a, $b) => $b['score'] <=> $a['score']);
    echo 'window_matches='.json_encode(array_slice($matches, 0, 3))."\n";

    // Also try search by phone
    if ($phoneDigits !== '') {
        $bySearch = $zoom->listRecordings(['search' => $phoneDigits, 'per_page' => 10]);
        echo 'search_count='.count($bySearch['recordings'] ?? [])."\n";
        if (!empty($bySearch['recordings'][0])) {
            $r0 = $bySearch['recordings'][0];
            echo 'search_first id='.($r0['id'] ?? '').' uuid='.($r0['call_uuid'] ?? '')."\n";
        }
    }

    $before = $log->recording_status;
    $fresh = $svc->resolveAndPersist($log->fresh(), max(1, (int) data_get($log->meta, 'recording_attempt', 0) + 1));
    echo "resolve {$before} -> {$fresh->recording_status} file=".($fresh->recording_file_id ?: '-')." attempt=".data_get($fresh->meta, 'recording_attempt')."\n";
}
"""


def main() -> int:
    ssh = connect()
    try:
        sftp = ssh.open_sftp()
        with sftp.file("/tmp/apex_findrec3.php", "w") as f:
            f.write(PHP)
        sftp.close()
        print(sudo_run(ssh, "php /tmp/apex_findrec3.php", check=False))
    finally:
        ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
