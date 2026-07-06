#!/usr/bin/env python3
"""Benchmark Communications Hub render time on production."""

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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

$user = User::where('name', 'admin_super_91a')->first();
if (! $user) {
    echo "admin user missing\n";
    exit(1);
}

Auth::login($user);
$controller = app(CommunicationsHubController::class);

foreach ([
    'default dialer' => '/admin/communications',
    'calls tab' => '/admin/communications?channel=calls',
] as $label => $path) {
    $request = Request::create($path, 'GET');
    $start = microtime(true);
    $response = $controller->index($request);
    $buildMs = round((microtime(true) - $start) * 1000);
    $renderStart = microtime(true);
  $html = $response->render();
    $renderMs = round((microtime(true) - $renderStart) * 1000);
    $view = method_exists($response, 'name') ? $response->name() : get_class($response);
    echo "{$label}: build={$buildMs}ms render={$renderMs}ms bytes=".strlen($html)." view={$view}\n";
}

Auth::logout();
"""


def main() -> int:
    ssh = connect()
    tmp = "/tmp/comm-hub-bench.php"
    sftp = ssh.open_sftp()
    with sftp.file(tmp, "w") as remote:
        remote.write(PHP)
    sftp.close()
    print(sudo_run(ssh, f"cd /var/www/apexone && sudo -u www-data php {tmp} && rm -f {tmp}"))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
