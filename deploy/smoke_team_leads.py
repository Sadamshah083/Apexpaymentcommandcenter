#!/usr/bin/env python3
"""Smoke-test team lead portal routes on production."""

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
use App\Http\Controllers\PipelineController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

$checks = ['setter_tl_48c', 'closer_tl_53d'];
foreach ($checks as $username) {
    $user = User::where('name', $username)->first();
    if (! $user) {
        echo "$username:MISSING_USER\n";
        continue;
    }
    Auth::login($user);
    try {
        $controller = app(PipelineController::class);
        $method = $username === 'setter_tl_48c' ? 'setterTeamDashboard' : 'closerTeamDashboard';
        $response = $controller->$method(Request::create('/', 'GET'));
        $status = method_exists($response, 'getStatusCode') ? $response->getStatusCode() : 200;
        echo "$username:OK status=$status view=" . ($response->name() ?? 'n/a') . "\n";
    } catch (Throwable $e) {
        echo "$username:FAIL " . get_class($e) . ': ' . $e->getMessage() . "\n";
    } finally {
        Auth::logout();
    }
}
"""


def main() -> int:
    ssh = connect()
    tmp = "/tmp/apexone-smoke.php"
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
