#!/usr/bin/env python3
"""Diagnose webphone prepare 422 and 502 on production."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import connect, sudo_run

PHP = r"""<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Services\Communications\CommunicationsWebphoneService;
use App\Services\Workspace\WorkspaceContextService;

$user = User::where('name', 'admin_super_91a')->first() ?: User::first();
if (! $user) {
    echo "NO_USER\n";
    exit(1);
}

$ws = app(WorkspaceContextService::class)->resolveActiveWorkspace($user);
$svc = app(CommunicationsWebphoneService::class);

echo 'ext_password_configured: ' . (filled(config('integrations.morpheus.extension_password')) ? 'yes' : 'no') . "\n";
echo 'morpheus_configured: ' . (app(\App\Services\Integrations\ZoomApiService::class)->isConfigured() ? 'yes' : 'no') . "\n";
echo 'webrtc_enabled: ' . (config('integrations.morpheus.webrtc_enabled') ? 'yes' : 'no') . "\n";

$config = $svc->configFor($user, $ws, '1001', 'admin.');
echo 'configFor: ' . ($config ? 'ok' : 'null') . "\n";
if ($config === null) {
    echo "config_error: check password / permissions\n";
}

$prep = $svc->prepareExtension($user, $ws, '1001', 'admin.');
echo 'prepare_ok: ' . (($prep['ok'] ?? false) ? 'yes' : 'no') . "\n";
echo 'prepare_message: ' . ($prep['message'] ?? ($prep['error'] ?? '')) . "\n";

$file = file_get_contents('app/Services/Communications/CommunicationsWebphoneService.php');
echo 'has_resolveExtension: ' . (str_contains($file, 'resolveExtension') ? 'yes' : 'no') . "\n";
"""


def main() -> int:
    ssh = connect()
    tmp = "/tmp/webphone-diagnose.php"
    sftp = ssh.open_sftp()
    with sftp.file(tmp, "w") as remote:
        remote.write(PHP)
    sftp.close()

    print("=== Laravel webphone diagnose ===")
    print(sudo_run(ssh, f"cd /var/www/apexone && sudo -u www-data php {tmp} && rm -f {tmp}"))

    print("\n=== Recent laravel errors ===")
    print(sudo_run(ssh, "tail -n 30 /var/www/apexone/storage/logs/laravel.log 2>/dev/null || echo 'no log'", check=False))

    print("\n=== nginx/php-fpm ===")
    print(sudo_run(ssh, "systemctl is-active nginx php8.3-fpm", check=False))

    print("\n=== local HTTP codes ===")
    for path in ["/up", "/admin/login"]:
        _, o, _ = ssh.exec_command(f"curl -sS -o /dev/null -w '%{{http_code}}' http://127.0.0.1{path}")
        print(f"  {path}: {o.read().decode().strip()}")

    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
