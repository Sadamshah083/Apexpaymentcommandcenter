#!/usr/bin/env python3
"""Fetch and summarize production logs for comm hub troubleshooting."""
from __future__ import annotations
import sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import REMOTE_APP, connect, sudo_run

ssh = connect()
sections = {
    "COMM HUB MONITOR (last 40 lines)": f"tail -40 {REMOTE_APP}/storage/logs/comm-hub-monitor.log 2>/dev/null || echo '(no monitor log)'",
    "LARAVEL (last 80, comm/morpheus/errors)": f"tail -500 {REMOTE_APP}/storage/logs/laravel.log 2>/dev/null | grep -iE 'morpheus|comm|originate|webphone|error|warn|exception' | tail -80 || tail -30 {REMOTE_APP}/storage/logs/laravel.log",
    "NGINX ORIGINATE/PREPARE (last 25)": "grep -E 'webphone/prepare|calls/originate' /var/log/nginx/access.log | tail -25",
    "NGINX ERRORS (last 20)": "tail -20 /var/log/nginx/error.log",
    "PHP-FPM (last 15 max_children)": "grep -i max_children /var/log/php8.3-fpm.log 2>/dev/null | tail -15 || echo '(none)'",
    "TIMER STATUS": "systemctl is-active apexone-comm-hub-monitor.timer 2>/dev/null; systemctl list-timers apexone-comm-hub-monitor.timer --no-pager 2>/dev/null | tail -3",
}

for title, cmd in sections.items():
    print("\n" + "=" * 60)
    print(title)
    print("=" * 60)
    try:
        out = sudo_run(ssh, cmd, check=False)
        print(out.encode("ascii", errors="replace").decode("ascii"))
    except Exception as e:
        print(f"(fetch error: {e})")

PHP = r"""<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\CommunicationCallLog;
use App\Services\Integrations\ZoomApiService;
$api = app(ZoomApiService::class);
echo "API: " . json_encode($api->connectionStatus()) . PHP_EOL;
echo "RECENT CALLS:" . PHP_EOL;
foreach (CommunicationCallLog::orderByDesc('id')->limit(8)->get() as $r) {
    echo "  {$r->created_at} ext={$r->from_extension} dest={$r->destination} uuid=" . ($r->morpheus_call_uuid ?: 'NULL') . PHP_EOL;
    if ($r->morpheus_call_uuid) {
        $cdr = $api->getCall($r->morpheus_call_uuid);
        if ($cdr) {
            echo "    outcome=" . ($cdr['call_outcome'] ?? $cdr['hangup_cause'] ?? '?') . " dest=" . ($cdr['destination_number'] ?? '?') . " billsec=" . ($cdr['billsec'] ?? 0) . " cid=" . ($cdr['caller_id_number'] ?? '?') . PHP_EOL;
        }
    }
}
try {
    $r = Illuminate\Support\Facades\Http::withHeaders(['X-API-Key' => config('integrations.morpheus.api_key')])
        ->timeout(8)->get('https://' . config('integrations.morpheus.host') . '/api/v1/call-control/cdr', ['limit' => 3, 'direction' => 'outbound']);
    if ($r->successful()) {
        foreach ($r->json('cdr') ?? [] as $row) {
            echo "  CDR {$row['call_uuid']} dest={$row['destination_number']} outcome={$row['call_outcome']} cause={$row['hangup_cause']} billsec={$row['billsec']}" . PHP_EOL;
        }
    }
} catch (Throwable $e) { echo "CDR err: " . $e->getMessage() . PHP_EOL; }
"""
tmp = "/tmp/log-check.php"
sftp = ssh.open_sftp()
with sftp.file(tmp, "w") as f:
    f.write(PHP)
sftp.close()
print("\n" + "=" * 60)
print("MORPHEUS + RECENT CDR")
print("=" * 60)
print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php {tmp}", check=False))
ssh.close()
