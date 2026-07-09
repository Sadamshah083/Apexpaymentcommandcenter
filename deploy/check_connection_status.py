#!/usr/bin/env python3
import sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import REMOTE_APP, connect, sudo_run

PHP = r"""<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$api = app(App\Services\Integrations\ZoomApiService::class);
$cb = app(App\Services\Integrations\MorpheusCircuitBreaker::class);
echo 'circuit_open=' . ($cb->isOpen() ? 'yes' : 'no') . PHP_EOL;
echo 'configured=' . ($api->isConfigured() ? 'yes' : 'no') . PHP_EOL;
echo 'connection=' . json_encode($api->connectionStatus()) . PHP_EOL;
$cached = Illuminate\Support\Facades\Cache::get('integrations.morpheus.connection_status');
echo 'cached=' . json_encode($cached) . PHP_EOL;
try {
  $r = Illuminate\Support\Facades\Http::withHeaders(['X-API-Key' => config('integrations.morpheus.api_key')])
    ->timeout(10)->get('https://' . config('integrations.morpheus.host') . '/api/v1/call-control/users', ['limit' => 1]);
  echo 'live_api_http=' . $r->status() . PHP_EOL;
} catch (Throwable $e) {
  echo 'live_api_err=' . $e->getMessage() . PHP_EOL;
}
"""

ssh = connect()
tmp = "/tmp/conn-status.php"
sftp = ssh.open_sftp()
with sftp.file(tmp, "w") as f:
    f.write(PHP)
sftp.close()
print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php {tmp}", check=False))
ssh.close()
