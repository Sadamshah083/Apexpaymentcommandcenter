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
$exts = $zoom->listExtensions(['limit' => 5])['extensions'] ?? [];
$users = $zoom->listUsers(['limit' => 50])['users'] ?? [];
$ext = null;
foreach ($exts as $e) {
    if ((string)($e['extension_num'] ?? '') === '1001') { $ext = $e; break; }
}
$user = null;
if ($ext && !empty($ext['user_id'])) {
    foreach ($users as $u) {
        if ((string)($u['id'] ?? '') === (string)$ext['user_id']) { $user = $u; break; }
    }
}
echo json_encode([
    'extension_keys' => $ext ? array_keys($ext) : [],
    'extension' => $ext,
    'user' => $user ? [
        'id' => $user['id'] ?? null,
        'username' => $user['username'] ?? null,
        'email' => $user['email'] ?? null,
        'status' => $user['status'] ?? null,
    ] : null,
], JSON_PRETTY_PRINT);
"""

ssh = connect()
enc = base64.b64encode(PHP.encode()).decode()
print(sudo_run(ssh, f"cd /var/www/apexone && echo {enc} | base64 -d | sudo -u www-data php", check=False))
ssh.close()
