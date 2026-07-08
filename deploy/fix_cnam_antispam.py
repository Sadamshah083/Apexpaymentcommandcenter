#!/usr/bin/env python3
"""Set business CNAM on all billing extensions and stop digit-only caller ID names (spam risk)."""

from __future__ import annotations

import base64
import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, set_env_vars, sudo_run, upload_files

FILES = [
    "app/Support/MorpheusSipIdentity.php",
    "app/Services/Communications/CommunicationsWebphoneService.php",
    "config/integrations.php",
]

CNAM = "ApexOne Payments"

PHP = r"""<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$cnam = __CNAM__;
$zoom = app(App\Services\Integrations\ZoomApiService::class);
$hub = app(App\Services\Communications\MorpheusHubService::class);
$agents = app(App\Services\Communications\CommunicationsAgentService::class);

$results = [];
foreach ($zoom->listExtensions(['limit' => 500])['extensions'] ?? [] as $ext) {
    $num = (string) ($ext['extension_num'] ?? '');
    if ($num === '' || !ctype_digit($num)) {
        continue;
    }

    $did = preg_replace('/\D/', '', (string) ($ext['outbound_cid_num'] ?? $ext['caller_id_num'] ?? ''));
    $patch = [
        'status' => 'active',
        'is_dialer_agent' => true,
        'override_campaign_cid' => true,
        'caller_id_name' => $cnam,
        'outbound_cid_name' => $cnam,
    ];
    if ($did !== '') {
        $patch['caller_id_num'] = $did;
        $patch['outbound_cid_num'] = $did;
    }

    $response = $zoom->updateExtension((string) $ext['id'], $patch);
    $dial = $agents->extensionDialOptions($num);

    $results[] = [
        'extension' => $num,
        'did' => $did,
        'caller_id_name' => $dial['caller_id_name'] ?? null,
        'caller_id_number' => $dial['caller_id_number'] ?? null,
        'ok' => !isset($response['error']) || isset($response['id']),
        'error' => $response['error'] ?? null,
    ];
}

$hub->bustCache();

echo json_encode([
    'ok' => collect($results)->every(fn ($r) => $r['ok'] ?? false),
    'cnam' => $cnam,
    'updated' => count($results),
    'results' => $results,
], JSON_PRETTY_PRINT);
"""


def main() -> int:
    print(f"Setting CNAM to '{CNAM}' on all Morpheus extensions...")

    php = PHP.replace("__CNAM__", json.dumps(CNAM))
    encoded = base64.b64encode(php.encode()).decode()

    ssh = connect()
    upload_files(ssh, [(ROOT / rel, rel.replace("\\", "/")) for rel in FILES])

    raw = sudo_run(
        ssh,
        f"cd {REMOTE_APP} && echo {encoded} | base64 -d | sudo -u www-data php",
    )
    print(raw)

    result = json.loads(raw)
    if not result.get("ok"):
        ssh.close()
        return 1

    set_env_vars(
        ssh,
        {
            "COMMUNICATIONS_DEFAULT_CALLER_ID_NAME": CNAM,
        },
    )
    sudo_run(
        ssh,
        f"cd {REMOTE_APP} && sudo -u www-data php artisan config:clear && sudo -u www-data php artisan config:cache",
    )
    ssh.close()

    print(f"Updated {result.get('updated')} extensions with CNAM '{CNAM}'")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
