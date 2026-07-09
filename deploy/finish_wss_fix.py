#!/usr/bin/env python3
import sys
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from deploy._ssh import REMOTE_APP, connect, sudo_run, sudo_run_batch

ssh = connect()
sudo_run(ssh, "systemctl reload nginx")
sudo_run(ssh, "systemctl reload php8.3-fpm", check=False)
php = """<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
use App\\Models\\User;
use App\\Services\\Communications\\CommunicationsWebphoneService;
use App\\Services\\Workspace\\WorkspaceContextService;
use Illuminate\\Support\\Facades\\Auth;
echo 'WSS=' . config('integrations.morpheus.sip_wss_url') . PHP_EOL;
$user = User::where('name', 'admin_super_91a')->first();
Auth::login($user);
$ws = app(WorkspaceContextService::class)->resolveActiveWorkspace($user);
$cfg = app(CommunicationsWebphoneService::class)->configFor($user, $ws, '1001', 'admin.');
echo 'CONFIG=' . json_encode($cfg) . PHP_EOL;
"""
tmp = "/tmp/wss-final.php"
sftp = ssh.open_sftp()
with sftp.file(tmp, "w") as f:
    f.write(php)
sftp.close()
print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php {tmp}", check=False))
for label, url in [
    ("direct", "https://apexone.morpheus.cx:7443/ws"),
    ("proxy", "https://crm.apexonepayments.com/morpheus-ws/ws"),
]:
    cmd = (
        f"curl -sk --http1.1 -o /dev/null -w '{label}=%{{http_code}}\\n' --max-time 8 "
        f"-H 'Connection: Upgrade' -H 'Upgrade: websocket' "
        f"-H 'Sec-WebSocket-Version: 13' -H 'Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==' {url}"
    )
    print(sudo_run(ssh, cmd, check=False))
ssh.close()
