#!/usr/bin/env python3
"""Try originate variants to ring PSTN directly."""
import sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import REMOTE_APP, connect, sudo_run

PHP = r"""<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$key = config('integrations.morpheus.api_key');
$host = config('integrations.morpheus.host');
$base = "https://{$host}/api/v1/call-control";
$campaignId = config('integrations.morpheus.default_campaign_id');
$dest = '12722001232';
$did = '13133851223';

$tests = [
    ['from' => '1001', 'to' => $dest, 'label' => 'ext1001->pstn'],
    ['from' => $did, 'to' => $dest, 'label' => "did{$did}->pstn"],
    ['from' => "+{$did}", 'to' => $dest, 'label' => 'did+E164->pstn'],
    ['from' => '1001', 'to' => $dest, 'caller_id_number' => $did, 'label' => 'ext1001+cid'],
];

foreach ($tests as $t) {
    $label = $t['label'];
    unset($t['label']);
    $body = array_merge([
        'timeout_sec' => 90,
        'campaign_id' => $campaignId,
        'caller_id_number' => $did,
    ], $t);
    echo "\n=== {$label} ===\n";
    echo json_encode($body)."\n";
    $r = Illuminate\Support\Facades\Http::withHeaders(['X-API-Key' => $key])
        ->timeout(25)->post("{$base}/calls/originate", $body);
    echo "HTTP {$r->status()} {$r->body()}\n";
    $uuid = $r->json('call_uuid');
    if (!$uuid) continue;
    for ($i = 0; $i < 8; $i++) {
        sleep(2);
        $lr = Illuminate\Support\Facades\Http::withHeaders(['X-API-Key' => $key])
            ->get("{$base}/calls/{$uuid}");
        $live = $lr->json('live') ?? false;
        $cr = Illuminate\Support\Facades\Http::withHeaders(['X-API-Key' => $key])
            ->get("{$base}/cdr", ['limit' => 8]);
        $cdr = null;
        foreach ($cr->json('cdr') ?? [] as $row) {
            if (($row['call_uuid'] ?? '') === $uuid) { $cdr = $row; break; }
        }
        echo "  t=".($i*2)."s live=".json_encode($live)
            ." billsec=".($cdr['billsec']??0)
            ." dest=".($cdr['destination_number']??'')
            ." cause=".($cdr['hangup_cause']??'')."\n";
        if (!$live && ($cdr['billsec']??0) > 0) break;
        if (!$live && in_array($cdr['hangup_cause']??'', ['USER_BUSY','UNALLOCATED_NUMBER'], true)) break;
    }
    Illuminate\Support\Facades\Http::withHeaders(['X-API-Key' => $key])
        ->post("{$base}/calls/{$uuid}/hangup");
    sleep(2);
}
"""

ssh = connect()
tmp = "/tmp/trunk-ring-test.php"
sftp = ssh.open_sftp()
with sftp.file(tmp, "w") as f: f.write(PHP)
sftp.close()
print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php {tmp}", check=False))
ssh.close()
