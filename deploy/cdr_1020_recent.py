#!/usr/bin/env python3
"""Recent CDR for extension 1020."""
from __future__ import annotations
import sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import REMOTE_APP, connect, sudo_run

PHP = r"""<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$key = config('integrations.morpheus.api_key');
$host = config('integrations.morpheus.host');
$r = Illuminate\Support\Facades\Http::withHeaders(['X-API-Key' => $key])
    ->get("https://".$host."/api/v1/call-control/cdr", ['limit' => 30]);
foreach ($r->json('cdr') ?? [] as $row) {
    $ext = $row['extension'] ?? $row['extension_num'] ?? '';
    $from = $row['caller_id_number'] ?? $row['from'] ?? '';
    $dest = $row['destination_number'] ?? $row['destination'] ?? '';
  if (str_contains((string)$ext, '1020') || str_contains((string)$from, '1020') || str_contains((string)$dest, '12722001232')) {
    echo json_encode([
      'uuid' => $row['call_uuid'] ?? null,
      'start' => $row['start_time'] ?? $row['created_at'] ?? null,
      'billsec' => $row['billsec'] ?? $row['duration_sec'] ?? null,
      'hangup' => $row['hangup_cause'] ?? null,
      'from' => $from,
      'dest' => $dest,
      'live' => $row['live'] ?? null,
    ])."\n";
  }
}
"""

ssh = connect()
tmp = "/tmp/cdr-1020.php"
sftp = ssh.open_sftp()
with sftp.file(tmp, "w") as f: f.write(PHP)
sftp.close()
print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php {tmp}", check=False))
ssh.close()
