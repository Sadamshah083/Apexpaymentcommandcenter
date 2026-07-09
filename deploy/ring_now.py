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
echo 'originate_method='.config('integrations.morpheus.originate_method')."\n";
echo 'ext_password_set='.(filled(config('integrations.morpheus.extension_password'))?'yes':'no')."\n";
$agents = app(App\Services\Communications\CommunicationsAgentService::class);
$r = $api->originateCall('1020', '+12722001232', $agents->extensionDialOptions('1020'));
echo json_encode($r, JSON_PRETTY_PRINT)."\n";
$uuid = $r['call_uuid'] ?? null;
if ($uuid) {
    for ($i=0;$i<15;$i++) {
        sleep(2);
        $s = $api->getCall($uuid);
        $live = $s['live'] ?? false;
        echo "t=".($i*2)."s live=".json_encode($live)."\n";
        if (!$live && $i>4) break;
    }
}
"""

ssh = connect()
tmp = "/tmp/ring-now.php"
sftp = ssh.open_sftp()
with sftp.file(tmp, "w") as f: f.write(PHP)
sftp.close()
print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php {tmp}", check=False))
ssh.close()
