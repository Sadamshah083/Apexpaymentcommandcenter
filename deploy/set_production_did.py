#!/usr/bin/env python3
"""Set production DID + update Morpheus extensions (no git, no migrations)."""

from __future__ import annotations

import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, set_env_vars, sudo_run, sudo_run_batch

DID = "13133851223"
DID_NAME = "Apex One"

PHP = """<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();

use App\\Services\\Communications\\CommunicationsAgentService;
use App\\Services\\Communications\\MorpheusHubService;
use App\\Services\\Integrations\\ZoomApiService;

$did = '__DID__';
$name = '__DID_NAME__';
$api = app(ZoomApiService::class);
$hub = app(MorpheusHubService::class);
$agents = app(CommunicationsAgentService::class);

echo "API: " . json_encode($api->connectionStatus()) . PHP_EOL;

$updated = 0;
foreach ($hub->extensions() as $ext) {
    $num = (string) ($ext['extension_num'] ?? '');
    if ($num === '') {
        continue;
    }
    $current = preg_replace('/\\D/', '', (string) ($ext['outbound_cid_num'] ?? $ext['caller_id_num'] ?? ''));
    if ($num !== '1001' && $current !== '' && $current !== '12016444668') {
        continue;
    }

    $id = (string) ($ext['id'] ?? '');
    if ($id === '') {
        echo 'SKIP ext ' . $num . ': missing id' . PHP_EOL;
        continue;
    }

    $patch = [
        'caller_id_num' => $did,
        'outbound_cid_num' => $did,
        'caller_id_name' => $name,
        'outbound_cid_name' => $name,
        'override_campaign_cid' => true,
        'status' => 'active',
    ];
    $result = $api->updateExtension($id, $patch);
    if (isset($result['error']) && ! isset($result['id'])) {
        echo 'FAIL ext ' . $num . ': ' . json_encode($result) . PHP_EOL;
        continue;
    }
    echo 'UPDATED ext ' . $num . ' -> DID ' . $did . PHP_EOL;
    $updated++;
}

$hub->bustCache();

$opts = $agents->extensionDialOptions('1001');
$ref = new ReflectionClass($api);
$m = $ref->getMethod('originatePayloadExtras');
$m->setAccessible(true);
$payload = $m->invoke($api, $opts);

echo 'DIAL_OPTIONS=' . json_encode($opts) . PHP_EOL;
echo 'ORIGINATE_EXTRAS=' . json_encode($payload) . PHP_EOL;
echo 'UPDATED_COUNT=' . $updated . PHP_EOL;
""".replace("__DID__", DID).replace("__DID_NAME__", DID_NAME)


def main() -> int:
    ssh = connect()

    print("Updating production .env DID + campaign config...")
    set_env_vars(ssh, {
        "COMMUNICATIONS_DEFAULT_CALLER_ID": DID,
        "MORPHEUS_DEFAULT_CAMPAIGN_ID": "6c753496-2efd-4783-aa85-eb6ec73bc512",
    })

    sudo_run_batch(ssh, [
        f"cd {REMOTE_APP} && sudo -u www-data php artisan config:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan config:cache",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan cache:clear",
    ])

    tmp = "/tmp/apexone-set-did.php"
    sftp = ssh.open_sftp()
    with sftp.file(tmp, "w") as remote:
        remote.write(PHP)
    sftp.close()

    print("\nUpdating Morpheus extensions + verifying originate payload...")
    out = sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php {tmp}", check=False)
    print(out)

    sudo_run(ssh, "systemctl reload php8.3-fpm", check=False)
    ssh.close()

    if "ORIGINATE_EXTRAS=" not in out:
        print("WARNING: verification output incomplete", file=sys.stderr)
        return 1
    if '"campaign_id"' not in out or DID not in out:
        print("WARNING: originate payload may still be missing campaign_id or DID", file=sys.stderr)
        return 1

    print("\nDone. DID set to", DID, "on server (no git push).")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
