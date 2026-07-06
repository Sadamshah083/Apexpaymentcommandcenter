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

$ext = null;
foreach ($zoom->listExtensions(['limit' => 500])['extensions'] ?? [] as $row) {
    if ((string)($row['extension_num'] ?? '') === '1020') {
        $ext = $row;
        break;
    }
}

$cdr = $zoom->listCdr(['limit' => 3]);
$raw = [];
foreach (($cdr['logs'] ?? []) as $log) {
    $raw[] = $log['raw'] ?? $log;
}

echo json_encode(['extension_1020' => $ext, 'cdr_raw_sample' => $raw], JSON_PRETTY_PRINT);
"""

def main():
    ssh = connect()
    enc = base64.b64encode(PHP.encode()).decode()
    print(sudo_run(ssh, f"cd {REMOTE_APP} && echo {enc} | base64 -d | sudo -u www-data php"))
    ssh.close()

if __name__ == "__main__":
    main()
