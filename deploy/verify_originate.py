#!/usr/bin/env python3
import json
import sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import REMOTE_APP, connect, sudo_run

PHP = r"""<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$ctc = app(App\Services\Communications\ZoomClickToCallService::class);
$api = app(App\Services\Integrations\ZoomApiService::class);
$agents = app(App\Services\Communications\CommunicationsAgentService::class);
echo 'prefix=' . config('integrations.morpheus.outbound_prefix') . PHP_EOL;
echo 'formatted=' . $ctc->formatOriginateDestination('+12722001232') . PHP_EOL;
$ext = '1020';
$opts = $agents->extensionDialOptions($ext);
$result = $api->originateCall($ext, '+12722001232', $opts);
echo 'originate=' . json_encode($result) . PHP_EOL;
$uuid = $result['call_uuid'] ?? null;
if ($uuid) {
    sleep(15);
    $snap = $api->getCall($uuid);
    echo 'snapshot=' . json_encode($snap) . PHP_EOL;
    echo 'dest_answered=' . ($api->destinationAnsweredOnCall($uuid, '+12722001232') ? 'yes' : 'no') . PHP_EOL;
}
"""

ssh = connect()
tmp = "/tmp/verify-originate.php"
sftp = ssh.open_sftp()
with sftp.file(tmp, "w") as f: f.write(PHP)
sftp.close()
out = sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php {tmp}", check=False)
print(out.encode("ascii", errors="replace").decode("ascii"))
ssh.close()
