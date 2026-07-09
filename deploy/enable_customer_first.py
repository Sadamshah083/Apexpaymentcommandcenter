#!/usr/bin/env python3
"""Enable ring-cell-first (customer_first) on production and test one dial."""
from __future__ import annotations
import sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import REMOTE_APP, connect, set_env_vars, sudo_run

PHP = r"""<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$api = app(App\Services\Integrations\ZoomApiService::class);
$agents = app(App\Services\Communications\CommunicationsAgentService::class);
$opts = array_merge($agents->extensionDialOptions('1020'), ['customer_first' => true]);
echo "Config customer_first=" . (config('integrations.morpheus.originate_customer_first') ? 'true' : 'false') . "\n";
echo "Config method=" . config('integrations.morpheus.originate_method') . "\n";
$api->clearExtensionForOutboundDial('1020', kickSip: false);
sleep(2);
$r = $api->originateCall('1020', '+12722001232', $opts);
echo json_encode($r, JSON_PRETTY_PRINT) . "\n";
$uuid = $r['call_uuid'] ?? null;
if ($uuid) {
    for ($i = 0; $i < 20; $i++) {
        sleep(1);
        $s = $api->getCall($uuid) ?? [];
        $live = (bool)($s['live'] ?? false);
        $bill = (int)($s['billsec'] ?? 0);
        $dest = $s['destination_number'] ?? '';
        $cause = $s['hangup_cause'] ?? '';
        echo sprintf("t=%02ds live=%s billsec=%d dest=%s cause=%s\n", $i+1, $live?'yes':'no', $bill, $dest, $cause);
        if (!$live && $cause && $bill < 1 && $i > 4) break;
    }
    $api->hangup($uuid);
}
"""

def main() -> int:
    ssh = connect()
    set_env_vars(ssh, {
        "MORPHEUS_ORIGINATE_CUSTOMER_FIRST": "true",
        "MORPHEUS_ORIGINATE_METHOD": "originate",
    })
    sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php artisan config:cache")
    tmp = "/tmp/test-customer-first.php"
    sftp = ssh.open_sftp()
    with sftp.file(tmp, "w") as f:
        f.write(PHP)
    sftp.close()
    print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php {tmp}", check=False))
    ssh.close()
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
