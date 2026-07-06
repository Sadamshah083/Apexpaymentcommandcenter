#!/usr/bin/env python3
import sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import connect, sudo_run

PHP = r"""<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Communications\CommunicationsInboxService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

$user = User::where('name', 'admin_super_91a')->first();
Auth::login($user);

$inbox = app(CommunicationsInboxService::class);
$request = Request::create('/admin/communications', 'GET');

foreach (['/admin/communications', '/admin/communications?channel=calls'] as $path) {
    $request = Request::create($path, 'GET');
    $start = microtime(true);
    $data = $inbox->build($request, 'admin.');
    $ms = round((microtime(true) - $start) * 1000);
    echo "{$path}: {$ms}ms channel={$data['channel']} panel={$data['panel']} calls=".count($data['callLogs'] ?? [])."\n";
}

Auth::logout();
"""

ssh = connect()
tmp = "/tmp/comm-profile.php"
sftp = ssh.open_sftp()
with sftp.file(tmp, "w") as f:
    f.write(PHP)
sftp.close()
print(sudo_run(ssh, f"cd /var/www/apexone && sudo -u www-data php {tmp} && rm -f {tmp}"))
ssh.close()
