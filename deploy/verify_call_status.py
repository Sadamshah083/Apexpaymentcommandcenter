#!/usr/bin/env python3
"""Verify production callStatus no longer returns 404 for unknown live calls."""
import base64, json, sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import REMOTE_APP, connect, sudo_run

UUID = sys.argv[1] if len(sys.argv) > 1 else "00000000-0000-0000-0000-000000000099"

PHP = rf"""<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$request = Illuminate\Http\Request::create(
    '/admin/communications/morpheus/calls/{UUID}?destination=%2B12722001232',
    'GET',
    ['destination' => '+12722001232']
);
$request->headers->set('Accept', 'application/json');
$response = $app->make(App\Http\Controllers\MorpheusHubController::class)->callStatus($request, '{UUID}');
$body = json_decode($response->getContent(), true);
$ctrl = file_get_contents(app_path('Http/Controllers/MorpheusHubController.php'));
echo json_encode([
    'status_code' => $response->getStatusCode(),
    'body' => $body,
    'has_pending_fix' => str_contains($ctrl, "'pending' => true"),
    'has_404_regression' => str_contains($ctrl, "'Call not found.'"),
], JSON_PRETTY_PRINT);
"""

ssh = connect()
enc = base64.b64encode(PHP.encode()).decode()
print(sudo_run(ssh, f"cd {REMOTE_APP} && echo {enc} | base64 -d | sudo -u www-data php"))
ssh.close()
