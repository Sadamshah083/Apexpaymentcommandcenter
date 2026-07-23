#!/usr/bin/env python3
"""Smoke-test one AI call summary on production."""

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
use App\Services\Communications\CallRecordingSummaryService;

$log = CommunicationCallLog::query()
    ->with('user:id,name')
    ->whereNotNull('disposition')
    ->orderByDesc('id')
    ->first();

if (!$log) { echo "NO_LOG\n"; exit; }

echo "log={$log->id} agent=".($log->user->name ?? '?')." status={$log->disposition} to={$log->to_phone}\n";
$svc = app(CallRecordingSummaryService::class);
$ws = \App\Models\Workspace::find($log->workspace_id);
$payload = $svc->summarize($ws, $log, true, true);
echo "model=".($payload['model'] ?? '')."\n";
echo "summary=".$payload['summary']."\n";
echo "leak=".(str_contains(strtolower($payload['summary']), 'caller (agent):') || str_contains(strtolower($payload['summary']), 'hard rules') ? 'YES' : 'NO')."\n";
"""


def main() -> int:
    ssh = connect()
    try:
        sftp = ssh.open_sftp()
        with sftp.file("/tmp/apex_sum_probe.php", "w") as f:
            f.write(PHP)
        sftp.close()
        print(sudo_run(ssh, "php /tmp/apex_sum_probe.php", check=False))
    finally:
        ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
