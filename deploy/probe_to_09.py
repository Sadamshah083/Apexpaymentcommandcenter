#!/usr/bin/env python3
import base64
import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import REMOTE_APP, connect, sudo_run

PHP = r"""<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$zoom = app(App\Services\Integrations\ZoomApiService::class);

$cdr = $zoom->listCdr(['limit' => 10]);
$recent = [];
foreach (($cdr['logs'] ?? []) as $log) {
    $raw = $log['raw'] ?? [];
    $recent[] = [
        'start' => $log['start_time'] ?? null,
        'from' => $log['from'] ?? null,
        'to' => $log['to'] ?? null,
        'result' => $log['result'] ?? null,
        'agent_extension' => $log['agent_extension'] ?? null,
        'raw_dest' => $raw['destination_number'] ?? null,
        'raw_caller' => $raw['caller_id_number'] ?? null,
        'uuid' => $raw['call_uuid'] ?? null,
    ];
}

$logs = App\Models\CommunicationCallLog::query()
    ->orderByDesc('id')
    ->limit(8)
    ->get(['to_phone','from_extension','morpheus_call_uuid','created_at','status'])
    ->toArray();

echo json_encode([
    'default_dial_destination' => config('integrations.communications.default_dial_destination'),
    'default_outbound_did' => config('integrations.communications.default_outbound_did'),
    'cdr' => $recent,
    'local_logs' => $logs,
], JSON_PRETTY_PRINT);
"""

def main():
    ssh = connect()
    enc = base64.b64encode(PHP.encode()).decode()
    print(sudo_run(ssh, f"cd {REMOTE_APP} && echo {enc} | base64 -d | sudo -u www-data php"))
    ssh.close()

if __name__ == "__main__":
    main()
