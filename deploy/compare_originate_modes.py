#!/usr/bin/env python3
"""Compare click-to-call vs originate on ext 1020."""
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
$opts = $agents->extensionDialOptions('1020');

foreach (['click-to-call', 'originate'] as $mode) {
    echo "\n===== {$mode} =====\n";
    if ($mode === 'originate') {
        $key = config('integrations.morpheus.api_key');
        $host = config('integrations.morpheus.host');
        $r = Illuminate\Support\Facades\Http::withHeaders(['X-API-Key' => $key])->timeout(20)
            ->post("https://{$host}/api/v1/call-control/calls/originate", array_merge([
                'from' => '1020', 'to' => '12722001232', 'timeout_sec' => 60,
                'campaign_id' => config('integrations.morpheus.default_campaign_id'),
            ], array_filter(['caller_id_number' => $opts['caller_id_number'] ?? null])));
        $result = $r->json() ?? [];
        $uuid = $result['call_uuid'] ?? null;
    } else {
        $result = $api->originateCall('1020', '+12722001232', $opts);
        $uuid = $result['call_uuid'] ?? null;
    }
    echo json_encode($result, JSON_PRETTY_PRINT)."\n";
    if ($uuid) {
        usleep(600000);
        echo "snapshot: ".json_encode($api->getCall($uuid))."\n";
        if ($uuid) $api->hangup($uuid);
    }
    sleep(2);
}
echo "\nActive calls:\n";
foreach ($api->listCalls()['calls'] ?? [] as $c) echo json_encode($c)."\n";
"""

ssh = connect()
tmp = "/tmp/compare-originate.php"
sftp = ssh.open_sftp()
with sftp.file(tmp, "w") as f: f.write(PHP)
sftp.close()
print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php {tmp}", check=False))
ssh.close()
