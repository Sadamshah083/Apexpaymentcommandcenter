#!/usr/bin/env python3
import base64, json, sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import connect, sudo_run

PHP = r"""<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$zoom = app(App\Services\Integrations\ZoomApiService::class);
$babarId = null;
foreach ($zoom->listUsers(['limit'=>500,'search'=>'babar'])['users'] ?? [] as $u) {
    if (($u['username'] ?? '') === 'babar') { $babarId = $u['id']; break; }
}
$exts = [];
foreach ($zoom->listExtensions(['limit'=>500])['extensions'] ?? [] as $e) {
    if (($e['user_id'] ?? '') === $babarId) $exts[] = $e;
}
echo json_encode(['babar_id'=>$babarId,'extensions'=>$exts], JSON_PRETTY_PRINT);
"""

ssh = connect()
enc = base64.b64encode(PHP.encode()).decode()
print(sudo_run(ssh, f"cd /var/www/apexone && echo {enc} | base64 -d | sudo -u www-data php"))
ssh.close()
