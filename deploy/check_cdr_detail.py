#!/usr/bin/env python3
import sys
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from deploy._ssh import REMOTE_APP, connect, sudo_run

PHP = r"""<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$api = app(App\Services\Integrations\ZoomApiService::class);
$uuids = [
    'ffeb00f7-0719-46c4-9296-353b9d907736',
    'ebb5dc21-69e1-4d2a-a5be-d35cad6b37c1',
];
foreach ($uuids as $uuid) {
    echo "=== $uuid ===" . PHP_EOL;
    $ref = new ReflectionClass($api);
    foreach (['quickGetCall', 'findRecentCdrByUuid'] as $method) {
        if (! $ref->hasMethod($method)) continue;
        $m = $ref->getMethod($method);
        $m->setAccessible(true);
        $row = $m->invoke($api, $uuid);
        if ($row) echo strtoupper($method) . '=' . json_encode($row) . PHP_EOL;
    }
}
try {
    $r = Illuminate\Support\Facades\Http::withHeaders(['X-API-Key' => config('integrations.morpheus.api_key')])
        ->get('https://' . config('integrations.morpheus.host') . '/api/v1/call-control/cdr', [
            'limit' => 5,
            'direction' => 'outbound',
        ]);
    echo 'CDR_HTTP=' . $r->status() . PHP_EOL;
    echo substr($r->body(), 0, 2000) . PHP_EOL;
} catch (Throwable $e) {
    echo $e->getMessage() . PHP_EOL;
}
"""
ssh = connect()
tmp = "/tmp/cdr-detail.php"
sftp = ssh.open_sftp()
with sftp.file(tmp, "w") as f:
    f.write(PHP)
sftp.close()
print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php {tmp}", check=False))
ssh.close()
