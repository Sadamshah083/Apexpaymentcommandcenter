#!/usr/bin/env python3
"""Ring customer FIRST: from=PSTN via trunk, to=agent extension."""
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
$campaignId = config('integrations.morpheus.default_campaign_id');
$did = $api->normalizeOriginateCallerId(config('integrations.communications.default_outbound_did'));
$cell = '12722001232';

$tests = [
    ['from' => $cell, 'to' => '1020', 'label' => 'CELL->1020 (ring cell first)'],
    ['from' => $cell, 'to' => '1001', 'label' => 'CELL->1001'],
    ['from' => '1'.$cell, 'to' => '1020', 'label' => 'CELL+1->1020'],
];

foreach ($tests as $t) {
    $label = $t['label'];
    unset($t['label']);
    echo "\n=== {$label} ===\n";
    $body = array_merge($t, [
        'timeout_sec' => 90,
        'campaign_id' => $campaignId,
        'caller_id_number' => $did,
    ]);
    $r = Illuminate\Support\Facades\Http::withHeaders(['X-API-Key' => $key])
        ->timeout(30)->post("{$base}/calls/originate", $body);
    echo "HTTP {$r->status()} {$r->body()}\n";
    $uuid = $r->json('call_uuid');
    if (!$uuid) continue;
    for ($i = 0; $i < 25; $i++) {
        sleep(2);
        $live = $api->getCall($uuid);
        $cr = Illuminate\Support\Facades\Http::withHeaders(['X-API-Key' => $key])
            ->get("{$base}/cdr", ['limit' => 5]);
        $cdr = null;
        foreach ($cr->json('cdr') ?? [] as $row) {
            if (($row['call_uuid'] ?? '') === $uuid) { $cdr = $row; break; }
        }
        echo "  {$i}s live=".json_encode($live['live']??false)
            ." billsec=".($cdr['billsec']??0)
            ." dest=".($cdr['destination_number']??'')
            ." cause=".($cdr['hangup_cause']??'')."\n";
        if (($cdr['billsec']??0) >= 1) { echo "*** ANSWERED ***\n"; sleep(8); break; }
        if (!($live['live']??false) && ($cdr['hangup_cause']??'') !== '') break;
    }
    Illuminate\Support\Facades\Http::withHeaders(['X-API-Key' => $key])
        ->post("{$base}/calls/{$uuid}/hangup");
    sleep(3);
}
"""

ssh = connect()
tmp = "/tmp/ring-cell-first.php"
sftp = ssh.open_sftp()
with sftp.file(tmp, "w") as f: f.write(PHP)
sftp.close()
print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php {tmp}", check=False))
ssh.close()
