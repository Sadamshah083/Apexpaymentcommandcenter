#!/usr/bin/env python3
"""Test click-to-call vs originate ringing on production."""
from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import REMOTE_APP, connect, sudo_run

EXT = sys.argv[1] if len(sys.argv) > 1 else "1001"
DEST = sys.argv[2] if len(sys.argv) > 2 else "+12722001232"
POLL = int(sys.argv[3]) if len(sys.argv) > 3 else 20

PHP = f"""<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();

use App\\Services\\Communications\\CommunicationsAgentService;
use App\\Services\\Integrations\\ZoomApiService;
use Illuminate\\Support\\Facades\\Http;

$api = app(ZoomApiService::class);
$agents = app(CommunicationsAgentService::class);
$ext = '{EXT}';
$dest = '{DEST}';
$pollSec = {POLL};
$opts = $agents->extensionDialOptions($ext);
$digits = preg_replace('/\\D/', '', $dest);
if (strlen($digits) === 10) $digits = '1'.$digits;
$host = config('integrations.morpheus.host');
$key = config('integrations.morpheus.api_key');
$base = "https://{{$host}}/api/v1/call-control";
$extras = array_filter([
    'campaign_id' => $opts['campaign_id'] ?? config('integrations.morpheus.default_campaign_id'),
    'caller_id_number' => $opts['caller_id_number'] ?? null,
    'timeout_sec' => 90,
], fn ($v) => filled($v));

echo "Extension online: " . json_encode($agents->extensionEndpointOnline($ext)) . "\\n";
echo "Originate method config: " . config('integrations.morpheus.originate_method') . "\\n\\n";

function pollCall($api, $uuid, $sec) {{
    $best = ['live' => false, 'max_billsec' => 0, 'causes' => []];
    for ($i = 0; $i < $sec; $i++) {{
        $s = $api->getCall($uuid) ?? [];
        $live = (bool)($s['live'] ?? false);
        $bill = (int)($s['billsec'] ?? $s['duration_sec'] ?? 0);
        $cause = strtoupper((string)($s['hangup_cause'] ?? ''));
        if ($live) $best['live'] = true;
        $best['max_billsec'] = max($best['max_billsec'], $bill);
        if ($cause !== '') $best['causes'][$cause] = ($best['causes'][$cause] ?? 0) + 1;
        echo "  t=".str_pad($i+1,2,' ',STR_PAD_LEFT)."s live=".($live?'Y':'N')." billsec=$bill cause=".($cause?:'-')." state=".($s['state']??$s['status']??'-')."\\n";
        if (!$live && $cause !== '' && $bill < 1 && $i >= 2) break;
        sleep(1);
    }}
    return $best;
}}

$tests = [
    'click-to-call' => array_merge(['extension' => preg_replace('/\\D/', '', $ext), 'destination' => $digits], $extras),
    'originate' => array_merge(['from' => preg_replace('/\\D/', '', $ext), 'to' => $digits], $extras),
    'app-originateCall' => null,
];

foreach ($tests as $label => $body) {{
    echo "===== $label =====\\n";
    if ($label === 'app-originateCall') {{
        $r = $api->originateCall($ext, '+'.$digits, $opts);
        echo json_encode($r, JSON_PRETTY_PRINT)."\\n";
        $uuid = $r['call_uuid'] ?? null;
    }} else {{
        $path = $label === 'click-to-call' ? '/click-to-call' : '/calls/originate';
        $resp = Http::timeout(25)->acceptJson()->withHeaders(['X-API-Key' => $key])->post($base.$path, $body);
        echo "HTTP ".$resp->status()." body=".$resp->body()."\\n";
        $uuid = $resp->json('call_uuid');
    }}
    if (!$uuid) {{ echo "NO UUID\\n\\n"; continue; }}
    $best = pollCall($api, $uuid, $pollSec);
    $api->hangup($uuid);
    $verdict = ($best['live'] || $best['max_billsec'] >= 1) ? 'RINGING/ACTIVE' : (
        isset($best['causes']['USER_BUSY']) ? 'USER_BUSY' : (
            isset($best['causes']['NO_USER_RESPONSE']) ? 'NO_AGENT_ANSWER' : 'ENDED_EARLY'
        )
    );
    echo "VERDICT: $verdict causes=".json_encode($best['causes'])."\\n\\n";
    sleep(2);
}}
"""

ssh = connect()
tmp = "/tmp/ring-test.php"
sftp = ssh.open_sftp()
with sftp.file(tmp, "w") as f:
    f.write(PHP)
sftp.close()
print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php {tmp}", check=False))
ssh.close()
