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
$host = config('integrations.morpheus.host');
$key = config('integrations.morpheus.api_key');
$campaign = config('integrations.morpheus.default_campaign_id');
$body = json_encode([
    'extension' => '1001',
    'destination' => '12722001232',
    'campaign_id' => $campaign,
    'caller_id_number' => '13133851223',
    'caller_id_name' => 'Apex One',
    'timeout_sec' => 30,
]);
echo "POST https://{$host}/api/v1/call-control/click-to-call\n";
echo "Body: {$body}\n\n";
$r = Illuminate\Support\Facades\Http::withHeaders(['X-API-Key' => $key, 'Content-Type' => 'application/json'])
    ->timeout(15)->post("https://{$host}/api/v1/call-control/click-to-call", json_decode($body, true));
echo "HTTP {$r->status()}\n";
echo $r->body() . "\n";
sleep(5);
$cdr = Illuminate\Support\Facades\Http::withHeaders(['X-API-Key' => $key])
    ->timeout(10)->get("https://{$host}/api/v1/call-control/cdr", ['limit' => 3]);
echo "\nCDR after 5s:\n";
foreach ($cdr->json('cdr') ?? [] as $row) {
    echo json_encode($row) . "\n";
}
"""

ssh = connect()
tmp = "/tmp/curl-originate.php"
sftp = ssh.open_sftp()
with sftp.file(tmp, "w") as f: f.write(PHP)
sftp.close()
print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php {tmp}", check=False))
ssh.close()
