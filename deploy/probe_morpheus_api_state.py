#!/usr/bin/env python3
import base64, json, sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import connect, sudo_run

PHP = r"""<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$cb = app(App\Services\Integrations\MorpheusCircuitBreaker::class);
$zoom = app(App\Services\Integrations\ZoomApiService::class);
$hub = app(App\Services\Communications\MorpheusHubService::class);
echo json_encode([
    'circuit_open' => $cb->isOpen(),
    'configured' => $zoom->isConfigured(),
    'connection' => $zoom->connectionStatus(),
    'hub_extensions_count' => count($hub->extensions()),
    'api_extensions_count' => count($zoom->listExtensions(['limit' => 5])['extensions'] ?? []),
], JSON_PRETTY_PRINT);
"""

ssh = connect()
enc = base64.b64encode(PHP.encode()).decode()
print(sudo_run(ssh, f"cd /var/www/apexone && echo {enc} | base64 -d | sudo -u www-data php", check=False))
ssh.close()
