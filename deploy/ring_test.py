#!/usr/bin/env python3
"""Place originate call and poll status for 45s — rings customer if agent answers."""
import sys
import time
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
$dest = config('integrations.communications.default_dial_destination') ?: '+12722001232';
$destDigits = preg_replace('/\D/', '', $dest);
$campaignId = config('integrations.morpheus.default_campaign_id');
$callerId = $api->normalizeOriginateCallerId(config('integrations.communications.default_outbound_did'));

echo "DEST={$dest} ({$destDigits})\n";
echo "CALLER_ID={$callerId}\n";
echo "CAMPAIGN={$campaignId}\n";

foreach ($api->listCalls()['calls'] ?? [] as $c) {
    $u = $c['uuid'] ?? '';
    if ($u) {
        $h = $api->hangup($u);
        echo "hangup {$u}: " . json_encode($h) . "\n";
    }
}

$key = config('integrations.morpheus.api_key');
$host = config('integrations.morpheus.host');
$base = "https://{$host}/api/v1/call-control";

foreach (['1001', '1020'] as $ext) {
    echo "\n=== ORIGINATE ext {$ext} -> {$destDigits} (no lead_id) ===\n";
    $body = [
        'from' => $ext,
        'to' => $destDigits,
        'timeout_sec' => 90,
        'campaign_id' => $campaignId,
        'caller_id_number' => $callerId,
    ];
    $r = Illuminate\Support\Facades\Http::withHeaders(['X-API-Key' => $key])
        ->timeout(25)->post("{$base}/calls/originate", $body);
    echo "HTTP {$r->status()} {$r->body()}\n";
    $uuid = $r->json('call_uuid');
    if (!$uuid) continue;

    for ($i = 0; $i < 18; $i++) {
        sleep(2);
        $snap = $api->getCall($uuid);
        $cdr = null;
        try {
            $cr = Illuminate\Support\Facades\Http::withHeaders(['X-API-Key' => $key])
                ->get("{$base}/cdr", ['limit' => 5]);
            foreach ($cr->json('cdr') ?? [] as $row) {
                if (($row['call_uuid'] ?? '') === $uuid) { $cdr = $row; break; }
            }
        } catch (Throwable $e) {}
        $live = $snap['live'] ?? false;
        $billsec = $cdr['billsec'] ?? $snap['billsec'] ?? 0;
        $cause = $cdr['hangup_cause'] ?? $snap['hangup_cause'] ?? '';
        $destNum = $cdr['destination_number'] ?? '';
        echo "  t=".($i*2)."s live=".json_encode($live)." billsec={$billsec} cause={$cause} cdr_dest={$destNum}\n";
        if (!$live && $billsec > 0) break;
        if (!$live && in_array($cause, ['USER_BUSY','CALL_REJECTED','UNALLOCATED_NUMBER'], true)) break;
    }
    $api->hangup($uuid);
    sleep(3);
}
"""

ssh = connect()
tmp = "/tmp/ring-test.php"
sftp = ssh.open_sftp()
with sftp.file(tmp, "w") as f:
    f.write(PHP)
sftp.close()
print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php {tmp}", check=False))
ssh.close()
