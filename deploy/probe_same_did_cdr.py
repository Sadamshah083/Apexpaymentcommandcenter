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
$ctrl = file_get_contents(app_path('Http/Controllers/MorpheusHubController.php'));
$start = strpos($ctrl, 'function callStatus');
$snippet = substr($ctrl, $start, 900);
$zoom = app(App\Services\Integrations\ZoomApiService::class);
$logs = $zoom->listCdr(['limit' => 30])['logs'] ?? [];
$bad = [];
foreach ($logs as $log) {
    $from = preg_replace('/\D/', '', (string)($log['from_phone'] ?? ''));
    $to = preg_replace('/\D/', '', (string)($log['to_phone'] ?? ''));
    if ($from !== '' && $from === $to) {
        $bad[] = $log;
    }
}
echo json_encode(['callStatus_snippet' => $snippet, 'same_from_to_logs' => array_slice($bad, 0, 5)], JSON_PRETTY_PRINT);
"""

ssh = connect()
enc = base64.b64encode(PHP.encode()).decode()
print(sudo_run(ssh, f"cd {REMOTE_APP} && echo {enc} | base64 -d | sudo -u www-data php"))
ssh.close()
