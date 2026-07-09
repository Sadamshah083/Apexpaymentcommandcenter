#!/usr/bin/env python3
import sys
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from deploy._ssh import REMOTE_APP, connect, set_env_vars, sudo_run, sudo_run_batch

PROXY_WSS = "wss://crm.apexonepayments.com/morpheus-ws/ws"
ssh = connect()
set_env_vars(ssh, {"MORPHEUS_SIP_WSS_URL": PROXY_WSS})
sudo_run_batch(ssh, [
    f"cd {REMOTE_APP} && sudo -u www-data php artisan config:clear",
    f"cd {REMOTE_APP} && sudo -u www-data php artisan config:cache",
])
php = """<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
use App\\Models\\User;
use App\\Services\\Communications\\CommunicationsWebphoneService;
use App\\Services\\Workspace\\WorkspaceContextService;
use Illuminate\\Support\\Facades\\Auth;
$user = User::where('name', 'admin_super_91a')->first();
Auth::login($user);
$ws = app(WorkspaceContextService::class)->resolveActiveWorkspace($user);
$cfg = app(CommunicationsWebphoneService::class)->configFor($user, $ws, '1001', 'admin.');
echo json_encode(['wss_url' => $cfg['wss_url'] ?? null, 'wss_url_fallback' => $cfg['wss_url_fallback'] ?? null], JSON_PRETTY_PRINT);
"""
tmp = "/tmp/wss-cfg.php"
sftp = ssh.open_sftp()
with sftp.file(tmp, "w") as f:
    f.write(php)
sftp.close()
print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php {tmp}", check=False))
ssh.close()
