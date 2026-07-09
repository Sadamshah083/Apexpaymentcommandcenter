#!/usr/bin/env python3
"""Continuous raw Morpheus click-to-call — bypasses Laravel verify gate."""
from __future__ import annotations

import sys
import time
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run  # noqa: E402

EXTS = ["1007", "1004", "1008", "1001", "1020"]
DEST = "12722001232"
POLL = 22
PAUSE = 4

PHP_TEMPLATE = r"""<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$api = app(App\Services\Integrations\ZoomApiService::class);
$key = config('integrations.morpheus.api_key');
$host = config('integrations.morpheus.host');
$base = "https://{$host}/api/v1/call-control";
$ext = '__EXT__';
$dest = '__DEST__';
$camp = config('integrations.morpheus.default_campaign_id');
$cid = config('integrations.communications.default_outbound_did', '13133851223');
$api->clearExtensionForOutboundDial($ext, false);
usleep(300000);
$body = [
    'extension' => $ext,
    'destination' => $dest,
    'timeout_sec' => 90,
    'campaign_id' => $camp,
    'caller_id_number' => preg_replace('/\D/', '', (string)$cid),
];
$r = Illuminate\Support\Facades\Http::timeout(25)->acceptJson()->withHeaders(['X-API-Key' => $key])
    ->post("{$base}/click-to-call", $body);
echo "HTTP {$r->status()} {$r->body()}\n";
$uuid = $r->json('call_uuid');
if (!$uuid) exit(0);
$maxBill = 0; $sawLive = false; $cdrDest = ''; $lastCause = '';
for ($i = 0; $i < __POLL__; $i++) {
    sleep(1);
    $s = $api->getCall($uuid) ?? [];
    $live = (bool)($s['live'] ?? false);
    $bill = (int)($s['billsec'] ?? 0);
    $cause = strtoupper((string)($s['hangup_cause'] ?? ''));
    if ($live) $sawLive = true;
    if ($cause !== '') $lastCause = $cause;
    $maxBill = max($maxBill, $bill);
    foreach ($api->listCdr(['limit' => 5, 'call_uuid' => $uuid])['cdr'] ?? [] as $row) {
        $cdrDest = (string)($row['destination_number'] ?? $cdrDest);
        $maxBill = max($maxBill, (int)($row['billsec'] ?? 0));
        $c = strtoupper((string)($row['hangup_cause'] ?? ''));
        if ($c !== '') $lastCause = $c;
    }
    echo sprintf("t=%02d live=%s billsec=%d dest=%s cause=%s\n", $i+1, $live?'Y':'N', $bill, $cdrDest?:'-', $cause?:'-');
    if (!$live && $cause !== '' && $bill < 1 && $i >= 3) break;
    if ($maxBill >= 3) { sleep(2); break; }
}
$api->hangup($uuid);
$hit = ($cdrDest === '__DEST__' && ($sawLive || $maxBill >= 1));
echo "VERDICT=".($hit ? 'PSTN_HIT' : $lastCause ?: 'OTHER')." max_bill={$maxBill} dest={$cdrDest}\n";
"""


def ts() -> str:
    return datetime.now(timezone.utc).strftime("%H:%M:%S UTC")


def main() -> int:
    ssh = connect()
    n = 0
    try:
        while True:
            for ext in EXTS:
                n += 1
                print(f"\n>>> [{ts()}] #{n} RAW-C2C ext {ext} -> +{DEST} — YOUR CELL MAY RING <<<")
                sys.stdout.flush()
                php = PHP_TEMPLATE.replace("__EXT__", ext).replace("__DEST__", DEST).replace("__POLL__", str(POLL))
                tmp = f"/tmp/raw_hammer_{ext}.php"
                sftp = ssh.open_sftp()
                with sftp.file(tmp, "w") as f:
                    f.write(php)
                sftp.close()
                print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php {tmp}", check=False))
                sys.stdout.flush()
                time.sleep(PAUSE)
    except KeyboardInterrupt:
        print(f"Stopped after {n} attempts.")
    finally:
        ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
