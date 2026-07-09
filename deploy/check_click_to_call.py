#!/usr/bin/env python3
"""Check click-to-call flow on production."""
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

use App\Models\CommunicationCallLog;
use App\Services\Communications\CommunicationsAgentService;
use App\Services\Integrations\ZoomApiService;

$api = app(ZoomApiService::class);
$agents = app(CommunicationsAgentService::class);

echo "API=" . json_encode($api->connectionStatus()) . PHP_EOL;
$opts = $agents->extensionDialOptions('1001');
$ref = new ReflectionClass($api);
$m = $ref->getMethod('originatePayloadExtras');
$m->setAccessible(true);
echo "ORIGINATE_EXTRAS=" . json_encode($m->invoke($api, $opts)) . PHP_EOL;

echo "RECENT_LOGS:" . PHP_EOL;
foreach (CommunicationCallLog::orderByDesc('id')->limit(6)->get() as $row) {
    echo $row->created_at . " | ext={$row->from_extension} | dest={$row->destination} | uuid=" . ($row->morpheus_call_uuid ?: 'NULL') . PHP_EOL;
}

try {
    $cdr = $api->listCdr(['limit' => 5, 'direction' => 'outbound']);
    echo "RECENT_CDR:" . PHP_EOL;
    foreach ($cdr['cdr'] ?? $cdr['cdrs'] ?? [] as $row) {
        $uuid = $row['call_uuid'] ?? $row['uuid'] ?? '?';
        $cid = $row['caller_id_number'] ?? '?';
        $dest = $row['destination'] ?? $row['destination_number'] ?? '?';
        $cause = $row['hangup_cause'] ?? $row['call_outcome'] ?? '?';
        $camp = $row['campaign_id'] ?? '?';
        echo "{$uuid} | cid={$cid} | dest={$dest} | cause={$cause} | camp={$camp}" . PHP_EOL;
    }
} catch (Throwable $e) {
    echo 'CDR_ERR=' . $e->getMessage() . PHP_EOL;
}
"""

ssh = connect()
tmp = "/tmp/c2c-check.php"
sftp = ssh.open_sftp()
with sftp.file(tmp, "w") as f:
    f.write(PHP)
sftp.close()
print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php {tmp}", check=False))
print("--- originate nginx (last 10) ---")
print(sudo_run(ssh, "grep 'calls/originate' /var/log/nginx/access.log | tail -10", check=False))
ssh.close()
