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
echo 'originate_method='.config('integrations.morpheus.originate_method')."\n";
$api = app(App\Services\Integrations\ZoomApiService::class);
$agents = app(App\Services\Communications\CommunicationsAgentService::class);
$r = $api->originateCall('1020', '+12722001232', $agents->extensionDialOptions('1020'));
echo json_encode($r, JSON_PRETTY_PRINT)."\n";
if ($uuid = $r['call_uuid'] ?? null) {
    sleep(1);
    echo 'live='.json_encode(($api->getCall($uuid)['live'] ?? false))."\n";
    $api->hangup($uuid);
}
echo 'ghost_calls='.count($api->listCalls()['calls'] ?? [])."\n";
"""

ssh = connect()
tmp = "/tmp/verify-originate-method.php"
sftp = ssh.open_sftp()
with sftp.file(tmp, "w") as f: f.write(PHP)
sftp.close()
print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php {tmp}", check=False))
ssh.close()
