#!/usr/bin/env python3
import sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import REMOTE_APP, connect, sudo_run

PHP = r"""<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Http\Controllers\MorpheusHubController;
use Illuminate\Http\Request;

$uuid = 'c13f9e2b-018b-489f-a9a9-c9bb51251f21';
$request = Request::create(
    "/admin/communications/morpheus/calls/{$uuid}/hangup",
    'POST',
    [],
    [],
    [],
    ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'],
    json_encode([
        'from_extension' => '1020',
        'destination' => '+12092880293',
        'originate_uuid' => $uuid,
        'related_uuids' => [$uuid],
    ])
);
$request->headers->set('Accept', 'application/json');

$user = App\Models\User::query()->find(2);
Auth::login($user);

$controller = app(MorpheusHubController::class);
$response = $controller->hangupCall($request, $uuid);
echo "hangup HTTP status: " . $response->getStatusCode() . "\n";
echo $response->getContent() . "\n";

$release = Request::create(
    '/admin/communications/morpheus/calls/release-extension',
    'POST',
    [],
    [],
    [],
    ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'],
    json_encode(['from_extension' => '1020', 'destination' => '+12092880293'])
);
$release->headers->set('Accept', 'application/json');
$r2 = $controller->releaseExtensionCalls($release);
echo "\nrelease HTTP status: " . $r2->getStatusCode() . "\n";
echo $r2->getContent() . "\n";

$events = Request::create(
    "/admin/communications/morpheus/calls/{$uuid}/events",
    'GET',
    ['destination' => '+12092880293']
);
Auth::login($user);
$e = $controller->streamCallEvents($events, $uuid);
echo "\nevents stream status: " . $e->getStatusCode() . "\n";
ob_start();
$e->sendContent();
$body = ob_get_clean();
echo "events sample: " . substr($body, 0, 400) . "\n";
"""

ssh = connect()
tmp = "/tmp/verify-hangup-ctrl.php"
sftp = ssh.open_sftp()
with sftp.file(tmp, "w") as f:
    f.write(PHP)
sftp.close()
checks = [
    ("bundle SSE", f"grep -c subscribeCallEvents {REMOTE_APP}/public/build/assets/communications-webphone-*.js | head -1"),
    ("syncFromCdr", f"grep -c syncFromCdr {REMOTE_APP}/app/Services/Communications/CommunicationsCallHistoryService.php"),
    ("events route", f"cd {REMOTE_APP} && sudo -u www-data php artisan route:list --name=events 2>/dev/null | tail -2"),
]
for label, cmd in checks:
    print(f"--- {label} ---")
    print(sudo_run(ssh, cmd, check=False))
print("--- controller test ---")
print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php {tmp}", check=False))
ssh.close()
