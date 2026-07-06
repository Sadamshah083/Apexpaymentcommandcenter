#!/usr/bin/env python3
import base64, json, sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import REMOTE_APP, connect, sudo_run

PHP = r"""<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$zoom = app(App\Services\Integrations\ZoomApiService::class);
$cdr = $zoom->listCdr(['limit' => 5]);
$out = [];
foreach (($cdr['logs'] ?? []) as $log) {
    $raw = $log['raw'] ?? [];
    $out[] = [
        'from' => $log['from'] ?? null,
        'from_phone' => $log['from_phone'] ?? null,
        'to' => $log['to'] ?? null,
        'agent_extension' => $log['agent_extension'] ?? null,
        'raw_caller' => $raw['caller_id_number'] ?? null,
        'raw_dest' => $raw['destination_number'] ?? null,
        'result' => $log['result'] ?? null,
    ];
}
echo json_encode($out, JSON_PRETTY_PRINT);
"""

ssh = connect()
enc = base64.b64encode(PHP.encode()).decode()
print(sudo_run(ssh, f"cd {REMOTE_APP} && echo {enc} | base64 -d | sudo -u www-data php"))
ssh.close()
