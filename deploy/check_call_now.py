#!/usr/bin/env python3
"""Check latest outbound call attempt on production."""
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

use App\Models\CommunicationCallLog;
use App\Services\Integrations\ZoomApiService;
use Illuminate\Support\Facades\Http;

$api = app(ZoomApiService::class);
$host = config('integrations.morpheus.host');
$key = config('integrations.morpheus.api_key');
$base = "https://{$host}/api/v1/call-control";

echo "=== SERVER TIME ===\n";
echo now()->toIso8601String() . "\n";

echo "\n=== WEBPHONE BUILD ===\n";
$manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);
echo ($manifest['resources/js/communications-webphone.js']['file'] ?? '?') . "\n";
echo 'MORPHEUS_SIP_WSS_URL=' . config('integrations.morpheus.sip_wss_url') . "\n";

echo "\n=== LIVE CALLS ===\n";
$live = $api->listCalls();
foreach (($live['calls'] ?? []) as $c) {
    echo json_encode($c) . "\n";
}
if (empty($live['calls'])) {
    echo "(none)\n";
}

echo "\n=== CRM CALL LOGS (last 5) ===\n";
foreach (CommunicationCallLog::orderByDesc('id')->limit(5)->get() as $r) {
    echo "id={$r->id} ext={$r->from_extension} to=".($r->to_phone ?: 'EMPTY')
        ." dir={$r->direction} result=".($r->result ?: '-')
        ." uuid=".($r->morpheus_call_uuid ?: 'NULL')
        ." at={$r->created_at}\n";
    if ($r->morpheus_call_uuid) {
        $snap = $api->getCall($r->morpheus_call_uuid);
        echo '  getCall: ' . json_encode($snap) . "\n";
    }
}

echo "\n=== CDR (last 10 outbound) ===\n";
$cdr = Http::withHeaders(['X-API-Key' => $key])->timeout(12)
    ->get("{$base}/cdr", ['limit' => 20, 'direction' => 'outbound']);
if ($cdr->successful()) {
    $rows = array_slice($cdr->json('cdr') ?? [], 0, 10);
    foreach ($rows as $row) {
        echo json_encode([
            'uuid' => $row['call_uuid'] ?? null,
            'ext' => $row['extension'] ?? ($row['extension_num'] ?? null),
            'from' => $row['caller_id_number'] ?? null,
            'to' => $row['destination_number'] ?? null,
            'outcome' => $row['call_outcome'] ?? null,
            'cause' => $row['hangup_cause'] ?? null,
            'billsec' => $row['billsec'] ?? 0,
            'start' => $row['start_time'] ?? ($row['start_stamp'] ?? null),
        ]) . "\n";
    }
} else {
    echo 'CDR HTTP ' . $cdr->status() . ' ' . substr($cdr->body(), 0, 200) . "\n";
}

echo "\n=== EXTENSION 1008 STATUS ===\n";
$exts = Http::withHeaders(['X-API-Key' => $key])->timeout(12)
    ->get("{$base}/extensions", ['limit' => 200]);
if ($exts->successful()) {
    foreach ($exts->json('extensions') ?? [] as $ext) {
        if ((string)($ext['extension_num'] ?? '') === '1008') {
            echo json_encode([
                'extension_num' => $ext['extension_num'] ?? null,
                'status' => $ext['status'] ?? null,
                'endpoint_online' => $ext['endpoint_online'] ?? null,
                'caller_id_num' => $ext['caller_id_num'] ?? null,
                'caller_id_name' => $ext['caller_id_name'] ?? null,
            ], JSON_PRETTY_PRINT) . "\n";
            break;
        }
    }
}

echo "\n=== NGINX ACCESS (originate last 3) ===\n";
"""

ssh = connect()
tmp = "/tmp/check-now-call.php"
sftp = ssh.open_sftp()
with sftp.file(tmp, "w") as f:
    f.write(PHP)
sftp.close()
print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php {tmp}", check=False))
print("--- nginx originate ---")
print(sudo_run(ssh, "grep -E 'originate|click-to-call|webphone' /var/log/nginx/access.log 2>/dev/null | tail -8", check=False))
print("--- laravel log tail ---")
print(sudo_run(ssh, f"tail -30 {REMOTE_APP}/storage/logs/laravel.log 2>/dev/null | grep -iE 'morpheus|originate|call|1008' || tail -5 {REMOTE_APP}/storage/logs/laravel.log", check=False))
ssh.close()
