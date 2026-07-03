#!/usr/bin/env python3
"""Smoke-test Communications Hub controllers on production."""

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

use App\Http\Controllers\CommunicationsHubController;
use App\Models\User;
use App\Services\Integrations\ZoomApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

$api = app(ZoomApiService::class);
$status = $api->connectionStatus();
echo 'API: '.($status['connected'] ? 'connected' : 'FAILED')."\n";
echo 'Campaign: '.config('integrations.morpheus.default_campaign_id')."\n";

$checks = [
    ['admin_super_91a', 'admin.'],
    ['setter_tl_48c', 'portal.'],
    ['setter_ag_k8z', 'portal.'],
];

foreach ($checks as [$username, $prefix]) {
    $user = User::where('name', $username)->first();
    if (! $user) {
        echo "$username:MISSING\n";
        continue;
    }
    Auth::login($user);
    try {
        $controller = app(CommunicationsHubController::class);
        $response = $controller->index(Request::create('/communications', 'GET'));
        $view = method_exists($response, 'name') ? $response->name() : get_class($response);
        echo "$username ($prefix): OK view=$view\n";
    } catch (Throwable $e) {
        echo "$username ($prefix): FAIL ".$e->getMessage()."\n";
    } finally {
        Auth::logout();
    }
}
"""


def main() -> int:
    ssh = connect()
    tmp = "/tmp/comm-hub-smoke.php"
    sftp = ssh.open_sftp()
    with sftp.file(tmp, "w") as remote:
        remote.write(PHP)
    sftp.close()
    out = sudo_run(ssh, f"cd /var/www/apexone && sudo -u www-data php {tmp} && rm -f {tmp}")
    print(out.strip())
    ssh.close()
    return 0 if "FAIL" not in out else 1


if __name__ == "__main__":
    raise SystemExit(main())
