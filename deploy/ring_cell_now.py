#!/usr/bin/env python3
"""Ring cell first — keep call up 45s."""
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
$did = $api->normalizeOriginateCallerId(config('integrations.communications.default_outbound_did'));

echo "DIALING YOUR CELL 12722001232 NOW — ANSWER YOUR PHONE\n";
$r = Illuminate\Support\Facades\Http::withHeaders(['X-API-Key' => $key])
    ->timeout(30)->post("{$base}/calls/originate", [
        'from' => '12722001232',
        'to' => '1020',
        'timeout_sec' => 90,
        'campaign_id' => config('integrations.morpheus.default_campaign_id'),
        'caller_id_number' => $did,
    ]);
echo $r->body()."\n";
$uuid = $r->json('call_uuid');
if (!$uuid) exit(1);
for ($i = 0; $i < 22; $i++) {
    sleep(2);
    $live = $api->getCall($uuid);
    $cr = Illuminate\Support\Facades\Http::withHeaders(['X-API-Key' => $key])
        ->get("{$base}/cdr", ['limit' => 10]);
    $cdr = null;
    foreach ($cr->json('cdr') ?? [] as $row) {
        if (($row['call_uuid'] ?? '') === $uuid) { $cdr = $row; break; }
    }
    echo ($i*2)."s live=".json_encode($live['live']??false)
        ." billsec=".($cdr['billsec']??0)
        ." hangup=".($cdr['hangup_cause']??'')."\n";
}
$api->hangup($uuid);
"""

ssh = connect()
tmp = "/tmp/ring-cell-now.php"
sftp = ssh.open_sftp()
with sftp.file(tmp, "w") as f: f.write(PHP)
sftp.close()
print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php {tmp}", check=False))
ssh.close()
