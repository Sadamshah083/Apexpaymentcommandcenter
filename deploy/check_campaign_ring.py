#!/usr/bin/env python3
"""Check campaign + originate via full app path."""
from __future__ import annotations

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
$agents = app(App\Services\Communications\CommunicationsAgentService::class);
$campId = config('integrations.morpheus.default_campaign_id');
echo "CAMPAIGN=" . json_encode($api->getCampaign((string)$campId), JSON_PRETTY_PRINT) . PHP_EOL;
echo "PROFILE=" . json_encode($api->outboundCallingProfile(), JSON_PRETTY_PRINT) . PHP_EOL;
$opts = $agents->extensionDialOptions('1001');
$r = $api->originateCall('1001', '+12722001232', $opts);
echo "ORIGINATE=" . json_encode($r, JSON_PRETTY_PRINT) . PHP_EOL;
$uuid = $r['call_uuid'] ?? null;
if ($uuid) {
    for ($i=0;$i<12;$i++) {
        sleep(1);
        $active = count($api->listCalls()['calls'] ?? []);
        $snap = $api->getCall($uuid);
        echo "t=".($i+1)." active=$active snap=".json_encode($snap).PHP_EOL;
    }
    $api->hangup($uuid);
}
echo "CDR=".json_encode(array_slice($api->listCdr(['limit'=>3,'direction'=>'outbound'])['cdr']??[],0,3), JSON_PRETTY_PRINT).PHP_EOL;
"""

ssh = connect()
tmp = "/tmp/camp-check.php"
sftp = ssh.open_sftp()
with sftp.file(tmp, "w") as f:
    f.write(PHP)
sftp.close()
print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php {tmp}", check=False))
ssh.close()
