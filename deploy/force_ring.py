#!/usr/bin/env python3
"""Force ring +12722001232 — kick busy ext, long timeout, poll 60s."""
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
$dest = '12722001232';
$key = config('integrations.morpheus.api_key');
$host = config('integrations.morpheus.host');
$base = "https://{$host}/api/v1/call-control";
$campaignId = config('integrations.morpheus.default_campaign_id');
$did = $api->normalizeOriginateCallerId(config('integrations.communications.default_outbound_did'));

echo "RINGING {$dest} NOW\n";

// Kick 1020 SIP + release ghosts
$api->kickExtensionSipRegistration('1020');
$api->releaseCallsForExtension('1020');
sleep(2);

foreach (['1020', '1001', '1004'] as $ext) {
    echo "\n>>> ORIGINATE {$ext} -> {$dest}\n";
    $r = Illuminate\Support\Facades\Http::withHeaders(['X-API-Key' => $key])
        ->timeout(30)->post("{$base}/calls/originate", [
            'from' => $ext,
            'to' => $dest,
            'timeout_sec' => 90,
            'campaign_id' => $campaignId,
            'caller_id_number' => $did,
        ]);
    echo "HTTP {$r->status()} {$r->body()}\n";
    $uuid = $r->json('call_uuid');
    if (!$uuid) continue;

    for ($i = 0; $i < 30; $i++) {
        sleep(2);
        $live = $api->getCall($uuid);
        $cdr = null;
        $cr = Illuminate\Support\Facades\Http::withHeaders(['X-API-Key' => $key])
            ->get("{$base}/cdr", ['limit' => 8]);
        foreach ($cr->json('cdr') ?? [] as $row) {
            if (($row['call_uuid'] ?? '') === $uuid) { $cdr = $row; break; }
        }
        $isLive = ($live['live'] ?? false) === true;
        $billsec = (int)($cdr['billsec'] ?? 0);
        $cause = $cdr['hangup_cause'] ?? '';
        $cdrDest = $cdr['destination_number'] ?? '';
        echo "  {$i}s live=".($isLive?'YES':'no')." billsec={$billsec} dest={$cdrDest} cause={$cause}\n";
        if ($billsec >= 3) {
            echo "*** CUSTOMER LEG CONNECTED billsec={$billsec} ***\n";
            sleep(5);
            break;
        }
        if (!$isLive && $cause !== '') break;
    }
    $api->hangup($uuid);
    sleep(2);
}
echo "\nDONE\n";
"""

ssh = connect()
tmp = "/tmp/force-ring.php"
sftp = ssh.open_sftp()
with sftp.file(tmp, "w") as f:
    f.write(PHP)
sftp.close()
print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php {tmp}", check=False))
ssh.close()
