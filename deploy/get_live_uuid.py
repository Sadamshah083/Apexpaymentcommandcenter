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
$key = config('integrations.morpheus.api_key');
$host = config('integrations.morpheus.host');
$base = "https://{$host}/api/v1/call-control";

echo "=== ACTIVE CALLS ===\n";
$calls = Illuminate\Support\Facades\Http::withHeaders(['X-API-Key' => $key])->get("{$base}/calls")->json('calls') ?? [];
echo "count=" . count($calls) . "\n";
foreach ($calls as $c) {
    echo json_encode($c, JSON_UNESCAPED_SLASHES) . "\n";
}

echo "\n=== RECENT CDR (last 5 outbound) ===\n";
$cdr = Illuminate\Support\Facades\Http::withHeaders(['X-API-Key' => $key])
    ->get("{$base}/cdr", ['limit' => 5, 'direction' => 'outbound'])->json('cdr') ?? [];
foreach ($cdr as $row) {
    echo ($row['call_uuid'] ?? '?') . " ext=" . ($row['extension'] ?? $row['agent_extension'] ?? '?')
        . " dest=" . ($row['destination_number'] ?? '?')
        . " billsec=" . ($row['billsec'] ?? 0)
        . " cause=" . ($row['hangup_cause'] ?? '')
        . " at=" . ($row['start_time'] ?? $row['created_at'] ?? '?') . "\n";
}

echo "\n=== LATEST CRM LOG ===\n";
$log = App\Models\CommunicationCallLog::orderByDesc('id')->first();
if ($log) {
    echo "uuid={$log->morpheus_call_uuid} ext={$log->from_extension} at={$log->created_at}\n";
    if ($log->morpheus_call_uuid) {
        $api = app(App\Services\Integrations\ZoomApiService::class);
        $s = $api->hubCallStatus($log->morpheus_call_uuid, $log->destination);
        echo "status: live=" . json_encode($s['live'] ?? null) . " ended=" . json_encode($s['call_ended'] ?? false) . " billsec=" . ($s['billsec'] ?? 0) . "\n";
    }
}
"""

ssh = connect()
tmp = "/tmp/live-now.php"
sftp = ssh.open_sftp()
with sftp.file(tmp, "w") as f:
    f.write(PHP)
sftp.close()
print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php {tmp}", check=False))
print("\n=== NGINX last 10 originate/status ===")
print(sudo_run(ssh, "grep -E 'originate|morpheus/calls/' /var/log/nginx/access.log | tail -10", check=False))
ssh.close()
