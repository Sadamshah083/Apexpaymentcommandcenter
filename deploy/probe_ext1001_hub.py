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
$hub = app(App\Services\Communications\MorpheusHubService::class);
$ext = collect($hub->extensions())->first(fn($e)=>(string)($e['extension_num']??'')==='1001');
$user = null;
if ($ext && !empty($ext['user_id'])) {
    $user = collect($hub->users())->first(fn($u)=>(string)($u['id']??'')===(string)$ext['user_id']);
}
echo json_encode(['extension'=>$ext,'user'=>$user], JSON_PRETTY_PRINT);
"""

ssh = connect()
enc = base64.b64encode(PHP.encode()).decode()
print(sudo_run(ssh, f"cd /var/www/apexone && echo {enc} | base64 -d | sudo -u www-data php", check=False))
ssh.close()
