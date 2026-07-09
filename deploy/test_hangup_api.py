#!/usr/bin/env python3
import sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import REMOTE_APP, connect, sudo_run

PHP = r"""<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Services\Integrations\ZoomApiService;
$api = app(ZoomApiService::class);
$uuid = 'c13f9e2b-018b-489f-a9a9-c9bb51251f21';
echo "direct hangup: " . json_encode($api->hangup($uuid)) . "\n";
echo "with context: " . json_encode($api->hangupWithContext($uuid, '1020', '+12092880293', [$uuid])) . "\n";
echo "release ext: " . json_encode($api->releaseExtensionCallsWithDestination('1020', '+12092880293')) . "\n";
"""

ssh = connect()
print("=== LARAVEL HANGUP ERRORS ===")
print(sudo_run(ssh, f"grep -i hangup {REMOTE_APP}/storage/logs/laravel.log 2>/dev/null | tail -30", check=False))
print("\n=== LARAVEL EXCEPTIONS (recent) ===")
print(sudo_run(ssh, f"grep -E 'ERROR|Exception' {REMOTE_APP}/storage/logs/laravel.log 2>/dev/null | tail -25", check=False))
print("\n=== NGINX HANGUP RECENT ===")
print(sudo_run(ssh, "grep hangup /var/log/nginx/access.log 2>/dev/null | tail -20", check=False))
tmp = "/tmp/test-hangup-api.php"
sftp = ssh.open_sftp()
with sftp.file(tmp, "w") as f:
    f.write(PHP)
sftp.close()
print("\n=== TEST HANGUP API ===")
print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php {tmp}", check=False))
ssh.close()
