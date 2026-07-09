#!/usr/bin/env python3
"""A/B test Morpheus originate: plain vs 482983# tech prefix."""
from __future__ import annotations
import json
import sys
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import REMOTE_APP, connect, sudo_run

EXT = "1020"
TESTS = [
    ("plain", "12722001232"),
    ("tech_prefix", "482983#12722001232"),
    ("tech_prefix_no_hash", "48298312722001232"),
]

PHP = r"""<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$api = app(App\Services\Integrations\ZoomApiService::class);
$agents = app(App\Services\Communications\CommunicationsAgentService::class);
$ext = '""" + EXT + r"""';
$opts = $agents->extensionDialOptions($ext);
$tests = json_decode('""" + json.dumps(TESTS) + r"""', true);
$key = config('integrations.morpheus.api_key');
$host = config('integrations.morpheus.host');
foreach ($tests as [$label, $dest]) {
    echo "\n=== $label => $dest ===\n";
    $r = Illuminate\Support\Facades\Http::withHeaders(['X-API-Key' => $key])
        ->timeout(20)->post("https://{$host}/api/v1/call-control/click-to-call", array_merge([
            'extension' => $ext,
            'destination' => $dest,
            'timeout_sec' => 45,
        ], array_filter([
            'caller_id_number' => $opts['caller_id_number'] ?? null,
            'caller_id_name' => $opts['caller_id_name'] ?? null,
            'campaign_id' => $opts['campaign_id'] ?? null,
        ])));
    echo "HTTP {$r->status()} {$r->body()}\n";
    $uuid = $r->json('call_uuid');
    if (!$uuid) continue;
    sleep(12);
    $cdr = Illuminate\Support\Facades\Http::withHeaders(['X-API-Key' => $key])
        ->get("https://{$host}/api/v1/call-control/cdr", ['limit' => 15]);
    foreach ($cdr->json('cdr') ?? [] as $row) {
        if (($row['call_uuid'] ?? '') === $uuid) {
            echo "CDR: dest={$row['destination_number']} billsec={$row['billsec']} cause={$row['hangup_cause']} outcome={$row['call_outcome']} answer=".($row['answer_time']??'null')."\n";
            break;
        }
    }
}
"""

ssh = connect()
tmp = "/tmp/ab-originate.php"
sftp = ssh.open_sftp()
with sftp.file(tmp, "w") as f:
    f.write(PHP)
sftp.close()
print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php {tmp}", check=False))
ssh.close()
