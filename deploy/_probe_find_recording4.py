#!/usr/bin/env python3
"""Inspect meta / alternate UUIDs on pending no-recording calls."""

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
use App\Services\Integrations\ZoomApiService;

$zoom = app(ZoomApiService::class);
$logs = CommunicationCallLog::query()
    ->where('created_at', '>=', now()->subDays(7))
    ->where('duration_sec', '>', 0)
    ->where(function ($x) {
        $x->whereNull('recording_file_id')->orWhere('recording_file_id', '');
    })
    ->whereIn('recording_status', ['pending', 'unavailable', 'none'])
    ->orderByDesc('id')
    ->limit(5)
    ->get();

foreach ($logs as $log) {
    echo "id={$log->id} uuid={$log->morpheus_call_uuid} status={$log->recording_status} phone={$log->to_phone}\n";
    echo 'meta='.json_encode($log->meta)."\n";
    $snap = $zoom->getCall((string) $log->morpheus_call_uuid);
    echo 'snap='.json_encode(array_intersect_key(is_array($snap) ? $snap : [], array_flip([
        'id','call_uuid','uuid','billsec','duration','duration_sec','has_recording','hangup_cause','status','destination_number','origination_uuid','bridge_uuid'
    ])))."\n\n";
}

$unavail = CommunicationCallLog::query()
    ->where('created_at', '>=', now()->subDays(7))
    ->where('recording_status', 'unavailable')
    ->where(function ($x) {
        $x->whereNull('recording_file_id')->orWhere('recording_file_id', '');
    })
    ->whereNotNull('morpheus_call_uuid')
    ->count();
echo "unavailable_missing_file={$unavail}\n";
"""


def main() -> int:
    ssh = connect()
    try:
        sftp = ssh.open_sftp()
        with sftp.file("/tmp/apex_findrec4.php", "w") as f:
            f.write(PHP)
        sftp.close()
        print(sudo_run(ssh, "php /tmp/apex_findrec4.php", check=False))
    finally:
        ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
