#!/usr/bin/env python3
"""Assign billing department DIDs to Morpheus extensions 1001–1020 (one DID each)."""

from __future__ import annotations

import base64
import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, set_env_vars, sudo_run, upload_files

MAPPING = {
    "1001": "13133851218",
    "1002": "13133851223",
    "1003": "13133851226",
    "1004": "13133851245",
    "1005": "13133851253",
    "1006": "14048501705",
    "1007": "14048501707",
    "1008": "14048501709",
    "1009": "14048501711",
    "1010": "14048501712",
    "1011": "14048501714",
    "1012": "14048501715",
    "1013": "14048501719",
    "1014": "14048501729",
    "1015": "14048501730",
    "1016": "14048501731",
    "1017": "12016444668",
    "1018": "12016485597",
    "1019": "12016485954",
    "1020": "12016485968",
}

PHP = r"""<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$mapping = json_decode(__MAPPING__, true);
$zoom = app(App\Services\Integrations\ZoomApiService::class);
$hub = app(App\Services\Communications\MorpheusHubService::class);

$byNum = [];
foreach ($zoom->listExtensions(['limit' => 500])['extensions'] ?? [] as $row) {
    $num = (string) ($row['extension_num'] ?? '');
    if ($num !== '') {
        $byNum[$num] = $row;
    }
}

$results = [];
foreach ($mapping as $extNum => $did) {
    $did = preg_replace('/\D/', '', (string) $did);
    $ext = $byNum[$extNum] ?? null;
    if (!$ext || empty($ext['id'])) {
        $results[] = ['extension' => $extNum, 'ok' => false, 'error' => 'extension not found'];
        continue;
    }

    $callerName = 'ApexOne Payments';
    $patch = $zoom->updateExtension((string) $ext['id'], [
        'status' => 'active',
        'is_dialer_agent' => true,
        'override_campaign_cid' => true,
        'caller_id_num' => $did,
        'outbound_cid_num' => $did,
        'caller_id_name' => $callerName,
        'outbound_cid_name' => $callerName,
    ]);

    $results[] = [
        'extension' => $extNum,
        'did' => $did,
        'ok' => !isset($patch['error']) || isset($patch['id']),
        'error' => $patch['error'] ?? null,
        'previous_did' => $ext['caller_id_num'] ?? $ext['outbound_cid_num'] ?? null,
    ];
}

$hub->bustCache();

echo json_encode([
    'ok' => collect($results)->every(fn ($r) => $r['ok'] ?? false),
    'assigned' => count(array_filter($results, fn ($r) => $r['ok'] ?? false)),
    'total' => count($results),
    'results' => $results,
], JSON_PRETTY_PRINT);
"""


def main() -> int:
    print(f"Assigning {len(MAPPING)} billing DIDs to extensions 1001–1020…")

    php = PHP.replace("__MAPPING__", json.dumps(json.dumps(MAPPING)))
    encoded = base64.b64encode(php.encode()).decode()

    ssh = connect()

    upload_files(ssh, [(ROOT / "config" / "morpheus_billing_dids.php", "config/morpheus_billing_dids.php")])

    raw = sudo_run(
        ssh,
        f"cd {REMOTE_APP} && echo {encoded} | base64 -d | sudo -u www-data php",
    )
    print(raw)

    try:
        result = json.loads(raw)
    except json.JSONDecodeError:
        print("Failed to parse Morpheus response")
        ssh.close()
        return 1

    if not result.get("ok"):
        failed = [r for r in result.get("results", []) if not r.get("ok")]
        print(f"Partial failure: {len(failed)} extension(s) failed")
        for row in failed:
            print(f"  ext {row.get('extension')}: {row.get('error')}")
        ssh.close()
        return 1

    print(f"Successfully assigned {result.get('assigned')}/{result.get('total')} DIDs")

    # Default fallback DID for extensions without one (admin line 1002)
    set_env_vars(ssh, {"COMMUNICATIONS_DEFAULT_OUTBOUND_DID": "+13133851223"})
    sudo_run(
        ssh,
        f"cd {REMOTE_APP} && sudo -u www-data php artisan config:clear && sudo -u www-data php artisan config:cache",
    )

    ssh.close()
    print("Done - hard refresh Communications Hub Phone agents to see updated DIDs.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
