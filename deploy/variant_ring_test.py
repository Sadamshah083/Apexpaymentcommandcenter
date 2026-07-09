#!/usr/bin/env python3
"""Try originate payload variations until CDR/live call appears."""
from __future__ import annotations

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
$camp = config('integrations.morpheus.default_campaign_id');
$client = fn() => Illuminate\Support\Facades\Http::timeout(25)->acceptJson()->withHeaders(['X-API-Key' => $key]);

$variants = [
    'c2c-minimal' => ['path'=>'click-to-call','body'=>['extension'=>'1001','destination'=>'12722001232','campaign_id'=>$camp]],
    'c2c-full' => ['path'=>'click-to-call','body'=>['extension'=>'1001','destination'=>'12722001232','timeout_sec'=>90,'campaign_id'=>$camp,'caller_id_number'=>'13133851223']],
    'orig-minimal' => ['path'=>'calls/originate','body'=>['from'=>'1001','to'=>'12722001232','campaign_id'=>$camp]],
    'orig-full' => ['path'=>'calls/originate','body'=>['from'=>'1001','to'=>'12722001232','timeout_sec'=>90,'campaign_id'=>$camp,'caller_id_number'=>'13133851223']],
    'c2c-1004' => ['path'=>'click-to-call','body'=>['extension'=>'1004','destination'=>'12722001232','timeout_sec'=>90,'campaign_id'=>$camp,'caller_id_number'=>'13133851223']],
];

foreach ($variants as $label => $spec) {
    echo "\n--- $label ---\n";
    $post = $client()->post("$base/{$spec['path']}", $spec['body']);
    echo "POST {$post->status()} {$post->body()}\n";
    $uuid = $post->json('call_uuid');
    if (!$uuid) continue;
    sleep(3);
    $get = $client()->get("$base/calls/$uuid");
    echo "GET {$get->status()} {$get->body()}\n";
    $cdr = $client()->get("$base/cdr", ['limit' => 20]);
    foreach ($cdr->json('cdr') ?? [] as $row) {
        if (($row['call_uuid'] ?? '') === $uuid) {
            echo "CDR_MATCH ".json_encode($row)."\n";
        }
    }
    $client()->post("$base/calls/$uuid/hangup");
}
"""

ssh = connect()
tmp = "/tmp/variant-ring.php"
sftp = ssh.open_sftp()
with sftp.file(tmp, "w") as f:
    f.write(PHP)
sftp.close()
print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php {tmp}", check=False))
ssh.close()
