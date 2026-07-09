#!/usr/bin/env python3
"""Diagnose webphone/SIP registration on production."""

from __future__ import annotations

import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, sudo_run_batch

PHP = r"""<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\Workspace;
use App\Services\Communications\CommunicationsWebphoneService;
use App\Services\Communications\MorpheusHubService;
use App\Services\Integrations\ZoomApiService;
use App\Services\Workspace\WorkspaceContextService;
use Illuminate\Support\Facades\Auth;

$api = app(ZoomApiService::class);
$webphone = app(CommunicationsWebphoneService::class);
$hub = app(MorpheusHubService::class);
$ctx = app(WorkspaceContextService::class);

echo "=== SIP ENV ===\n";
foreach ([
    'MORPHEUS_HOST',
    'MORPHEUS_SIP_HOST',
    'MORPHEUS_SIP_WSS_URL',
    'MORPHEUS_WEBRTC_ENABLED',
    'MORPHEUS_EXTENSION_PASSWORD',
] as $k) {
    $v = match ($k) {
        'MORPHEUS_HOST' => config('integrations.morpheus.host'),
        'MORPHEUS_SIP_HOST' => config('integrations.morpheus.sip_host'),
        'MORPHEUS_SIP_WSS_URL' => config('integrations.morpheus.sip_wss_url'),
        'MORPHEUS_WEBRTC_ENABLED' => config('integrations.morpheus.webrtc_enabled') ? 'true' : 'false',
        'MORPHEUS_EXTENSION_PASSWORD' => filled(config('integrations.morpheus.extension_password')) ? '(set)' : '(empty)',
        default => '',
    };
    echo $k . '=' . ($v ?: '(empty)') . "\n";
}

echo "\n=== API ===\n";
echo json_encode($api->connectionStatus()) . "\n";

echo "\n=== EXTENSION 1001 ===\n";
foreach ($hub->extensions() as $ext) {
    if ((string) ($ext['extension_num'] ?? '') !== '1001') {
        continue;
    }
    foreach (['id', 'extension_num', 'status', 'password'] as $k) {
        if (! array_key_exists($k, $ext)) {
            continue;
        }
        $val = $ext[$k];
        if ($k === 'password') {
            $val = filled($val) ? '(set)' : '(empty)';
        }
        echo $k . '=' . json_encode($val) . "\n";
    }
}

$user = User::where('name', 'admin_super_91a')->first()
    ?? User::where('email', 'like', '%admin%')->orderBy('id')->first();
if (! $user) {
    echo "\nNo admin user for prepare test\n";
    exit(0);
}

Auth::login($user);
$workspace = $ctx->resolveActiveWorkspace($user);
$config = $webphone->configFor($user, $workspace, '1001', 'admin.');
$prepare = $webphone->prepareExtension($user, $workspace, '1001', 'admin.');

echo "\n=== CONFIG FOR 1001 (admin) ===\n";
echo json_encode($config) . "\n";
echo "\n=== PREPARE RESULT ===\n";
echo json_encode($prepare) . "\n";
"""


def main() -> int:
    ssh = connect()
    tmp = "/tmp/webphone-sip-diagnose.php"
    sftp = ssh.open_sftp()
    with sftp.file(tmp, "w") as remote:
        remote.write(PHP)
    sftp.close()

    print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php {tmp}"))

    print("--- NGINX webphone/prepare (last 15) ---")
    print(
        sudo_run(
            ssh,
            "grep 'webphone/prepare' /var/log/nginx/access.log | tail -15",
            check=False,
        )
    )

    wss_hosts = sudo_run(
        ssh,
        f"cd {REMOTE_APP} && sudo -u www-data php -r \"require 'vendor/autoload.php';"
        f" \\$a=require 'bootstrap/app.php'; \\$a->make(Illuminate\\\\Contracts\\\\Console\\\\Kernel::class)->bootstrap();"
        f" echo config('integrations.morpheus.sip_wss_url') ?: 'wss://'.config('integrations.morpheus.sip_host').':7443';\"",
        check=False,
    )
    wss = wss_hosts.strip().split("\n")[-1] if wss_hosts else ""
    if wss:
        print(f"--- WSS probe {wss} ---")
        host = wss.replace("wss://", "").split("/")[0].split(":")[0]
        port = "7443"
        if ":" in wss.replace("wss://", "").split("/")[0]:
            port = wss.replace("wss://", "").split("/")[0].split(":")[1]
        print(sudo_run(ssh, f"curl -vk --max-time 8 {wss.replace('wss://', 'https://').rstrip('/')}/ 2>&1 | head -25", check=False))
        print(sudo_run(ssh, f"getent hosts {host} 2>/dev/null || true", check=False))
        print(sudo_run(ssh, f"nc -zv -w 5 {host} {port} 2>&1 || true", check=False))

    ssh.close()
    return 0


def nginx_probe() -> int:
    ssh = connect()
    print("--- nginx morpheus-ws ---")
    print(sudo_run(ssh, "grep -R morpheus-ws /etc/nginx/ 2>/dev/null | head -40", check=False))
    print("--- prod CommunicationsWebphoneService ---")
    print(sudo_run(ssh, "sed -n '1,120p' /var/www/apexone/app/Services/Communications/CommunicationsWebphoneService.php", check=False))
    print("--- wss upgrade ---")
    print(
        sudo_run(
            ssh,
            'curl -sk -o /dev/null -w "%{http_code}" -H "Connection: Upgrade" -H "Upgrade: websocket" '
            '-H "Sec-WebSocket-Version: 13" -H "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==" '
            "https://crm.apexonepayments.com/morpheus-ws/ws",
            check=False,
        )
    )
    print("--- direct morpheus wss ---")
    print(sudo_run(ssh, "curl -vk --max-time 8 https://apexone.morpheus.cx:7443/ws 2>&1 | head -20", check=False))
    ssh.close()
    return 0


if __name__ == "__main__":
    import sys
    if len(sys.argv) > 1 and sys.argv[1] == "nginx":
        raise SystemExit(nginx_probe())
    raise SystemExit(main())
