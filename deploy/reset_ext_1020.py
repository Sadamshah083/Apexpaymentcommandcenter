#!/usr/bin/env python3
"""Reset ext 1020 SIP registration + list extension state."""
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
$key = config('integrations.morpheus.api_key');
$host = config('integrations.morpheus.host');
$base = "https://{$host}/api/v1/call-control";

echo "=== ACTIVE CALLS ===\n";
foreach ($api->listCalls()['calls'] ?? [] as $c) echo json_encode($c)."\n";

echo "\n=== EXTENSIONS ===\n";
foreach ($api->listExtensions(['limit' => 50])['extensions'] ?? [] as $ext) {
    $num = $ext['extension_num'] ?? '';
    if (!in_array($num, ['1001','1020','1004'], true)) continue;
    echo json_encode($ext)."\n";
}

// Rotate 1020 password to kick stale SIP registrations (same as env password if set)
$ext1020 = null;
foreach ($api->listExtensions(['limit' => 50])['extensions'] ?? [] as $ext) {
    if ((string)($ext['extension_num'] ?? '') === '1020') { $ext1020 = $ext; break; }
}
if ($ext1020 && filled($ext1020['id'] ?? null)) {
    $newPass = config('integrations.morpheus.extension_password') ?: 'ApexHub1020!';
    echo "\n=== PATCH ext 1020 password (kick registrations) ===\n";
    $patched = $api->updateExtension((string)$ext1020['id'], [
        'status' => 'active',
        'password' => $newPass,
    ]);
    echo json_encode($patched, JSON_PRETTY_PRINT)."\n";
    sleep(3);
}

echo "\n=== ORIGINATE 1020 after reset ===\n";
$agents = app(App\Services\Communications\CommunicationsAgentService::class);
$r = $api->originateCall('1020', '+12722001232', $agents->extensionDialOptions('1020'));
echo json_encode($r, JSON_PRETTY_PRINT)."\n";
$uuid = $r['call_uuid'] ?? null;
if ($uuid) {
    for ($i=0; $i<6; $i++) {
        sleep(2);
        $s = $api->getCall($uuid);
        echo "poll live=".json_encode($s['live']??false)." state=".($s['state']??'')."\n";
        if (!($s['live']??false) && $i>2) break;
    }
    $api->hangup($uuid);
}
"""

ssh = connect()
tmp = "/tmp/reset-ext1020.php"
sftp = ssh.open_sftp()
with sftp.file(tmp, "w") as f: f.write(PHP)
sftp.close()
print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php {tmp}", check=False))
ssh.close()
