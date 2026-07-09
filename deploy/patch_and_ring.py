#!/usr/bin/env python3
"""Patch campaign timeouts + ring cell 120s."""
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
$campaignId = config('integrations.morpheus.default_campaign_id');
echo "PATCH campaign timeouts...\n";
echo json_encode($api->updateCampaign((string)$campaignId, [
    'dial_mode' => 'manual',
    'status' => 'active',
    'require_disposition' => false,
    'ring_timeout' => 90,
    'drop_timeout' => 45,
]), JSON_PRETTY_PRINT)."\n";

$key = config('integrations.morpheus.api_key');
$host = config('integrations.morpheus.host');
$base = "https://{$host}/api/v1/call-control";
$did = $api->normalizeOriginateCallerId(config('integrations.communications.default_outbound_did'));

echo "\nRINGING 12722001232 (120s timeout) — ANSWER YOUR PHONE\n";
$r = Illuminate\Support\Facades\Http::withHeaders(['X-API-Key' => $key])
    ->timeout(30)->post("{$base}/calls/originate", [
        'from' => '12722001232',
        'to' => '1020',
        'timeout_sec' => 120,
        'campaign_id' => $campaignId,
        'caller_id_number' => $did,
    ]);
echo $r->body()."\n";
$uuid = $r->json('call_uuid');
if ($uuid) {
    for ($i = 0; $i < 55; $i++) {
        sleep(2);
        $live = $api->getCall($uuid);
        echo ($i*2)."s live=".json_encode($live['live']??false)."\n";
        if (!($live['live']??false) && $i > 5) break;
    }
}
"""

ssh = connect()
tmp = "/tmp/patch-and-ring.php"
sftp = ssh.open_sftp()
with sftp.file(tmp, "w") as f: f.write(PHP)
sftp.close()
print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php {tmp}", check=False))
ssh.close()
