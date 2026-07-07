#!/usr/bin/env python3
"""Analyze Morpheus calls from the past hour on production."""
from __future__ import annotations

import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run

PHP = r"""<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\CommunicationCallLog;
use App\Services\Integrations\ZoomApiService;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;

$since = Carbon::now('UTC')->subHour();
$key = config('integrations.morpheus.api_key');
$host = config('integrations.morpheus.host');
$base = "https://{$host}/api/v1/call-control";
$api = app(ZoomApiService::class);

echo "=== WINDOW ===\n";
echo "since={$since->toIso8601String()} now=" . now('UTC')->toIso8601String() . "\n";
echo "originate_method=" . config('integrations.morpheus.originate_method') . "\n";
echo "customer_first=" . (config('integrations.morpheus.originate_customer_first') ? 'true' : 'false') . "\n";
echo "sip_wss=" . config('integrations.morpheus.sip_wss_url') . "\n";
echo "default_did=" . config('integrations.communications.default_outbound_did') . "\n";

echo "\n=== CRM CALL LOGS (past hour) ===\n";
$logs = CommunicationCallLog::where('created_at', '>=', $since)->orderBy('id')->get();
foreach ($logs as $r) {
    $row = [
        'id' => $r->id,
        'ext' => $r->from_extension,
        'to' => $r->to_phone,
        'uuid' => $r->morpheus_call_uuid,
        'status' => $r->status,
        'at' => $r->created_at?->toIso8601String(),
    ];
    if ($r->morpheus_call_uuid) {
        $snap = $api->getCall($r->morpheus_call_uuid);
        $row['cdr'] = [
            'dest' => $snap['destination_number'] ?? null,
            'billsec' => $snap['billsec'] ?? null,
            'cause' => $snap['hangup_cause'] ?? null,
            'outcome' => $snap['status'] ?? null,
        ];
    }
    echo json_encode($row) . "\n";
}
if ($logs->isEmpty()) echo "(none)\n";

echo "\n=== CDR OUTBOUND (past hour) ===\n";
$cdr = Http::withHeaders(['X-API-Key' => $key])->timeout(15)
    ->get("{$base}/cdr", ['limit' => 100, 'direction' => 'outbound']);
$count = 0;
foreach ($cdr->json('cdr') ?? [] as $row) {
    $start = $row['start_time'] ?? $row['start_stamp'] ?? null;
    if (!$start) continue;
    $t = Carbon::parse($start);
    if ($t->lt($since)) continue;
    $count++;
    echo json_encode([
        'uuid' => $row['call_uuid'] ?? null,
        'ext' => $row['agent_extension'] ?? null,
        'from_num' => $row['caller_id_number'] ?? null,
        'from_name' => $row['caller_id_name'] ?? null,
        'to' => $row['destination_number'] ?? null,
        'billsec' => $row['billsec'] ?? 0,
        'cause' => $row['hangup_cause'] ?? null,
        'outcome' => $row['call_outcome'] ?? null,
        'start' => $start,
    ]) . "\n";
}
if ($count === 0) echo "(none)\n";

echo "\n=== LIVE CALLS ===\n";
$live = $api->listCalls();
echo json_encode($live['calls'] ?? []) . "\n";

echo "\n=== EXTENSIONS 1008/1020 ===\n";
$exts = Http::withHeaders(['X-API-Key' => $key])->timeout(15)
    ->get("{$base}/extensions", ['limit' => 200]);
foreach ($exts->json('extensions') ?? [] as $ext) {
    $num = (string)($ext['extension_num'] ?? '');
    if (!in_array($num, ['1008', '1020', '1001'], true)) continue;
    echo json_encode([
        'ext' => $num,
        'status' => $ext['status'] ?? null,
        'online' => $ext['endpoint_online'] ?? null,
        'cid_name' => $ext['caller_id_name'] ?? null,
        'cid_num' => $ext['caller_id_num'] ?? null,
        'outbound_cid' => $ext['outbound_cid_num'] ?? null,
    ]) . "\n";
}
"""

ssh = connect()
tmp = "/tmp/analyze-hour.php"
sftp = ssh.open_sftp()
with sftp.file(tmp, "w") as f:
    f.write(PHP)
sftp.close()
print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php {tmp}", check=False))
ssh.close()
