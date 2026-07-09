#!/usr/bin/env python3
"""Raw Morpheus API ring test — no Laravel abstractions."""
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
$client = fn() => Illuminate\Support\Facades\Http::timeout(20)->acceptJson()->withHeaders(['X-API-Key' => $key]);

$body = [
    'extension' => '1001',
    'destination' => '12722001232',
    'timeout_sec' => 90,
    'campaign_id' => config('integrations.morpheus.default_campaign_id'),
    'caller_id_number' => '13133851223',
];

echo "CB=" . json_encode(app(App\Services\Integrations\MorpheusCircuitBreaker::class)->isOpen()) . "\n";

foreach (['click-to-call', 'calls/originate'] as $mode) {
    echo "\n=== $mode ===\n";
    $payload = $mode === 'click-to-call'
        ? $body
        : ['from' => '1001', 'to' => '12722001232', 'timeout_sec' => 90, 'campaign_id' => $body['campaign_id'], 'caller_id_number' => '13133851223'];
    $post = $client()->post("$base/$mode", $payload);
    echo "POST HTTP {$post->status()} {$post->body()}\n";
    $uuid = $post->json('call_uuid');
    if (!$uuid) continue;
    for ($t = 1; $t <= 8; $t++) {
        sleep(1);
        $get = $client()->get("$base/calls/$uuid");
        $list = $client()->get("$base/calls");
        $active = count($list->json('calls') ?? []);
        echo "  t=$t GET_call HTTP {$get->status()} body=".substr($get->body(),0,200)." active=$active\n";
    }
    $client()->post("$base/calls/$uuid/hangup");
    sleep(2);
    $cdr = $client()->get("$base/cdr", ['limit' => 5, 'direction' => 'outbound']);
    echo "  CDR tail: ".substr($cdr->body(),0,400)."\n";
}
"""

ssh = connect()
tmp = "/tmp/raw-morpheus-ring.php"
sftp = ssh.open_sftp()
with sftp.file(tmp, "w") as f:
    f.write(PHP)
sftp.close()
print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php {tmp}", check=False))
ssh.close()
