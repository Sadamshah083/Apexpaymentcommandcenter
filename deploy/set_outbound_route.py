#!/usr/bin/env python3
"""Lock outbound route: caller ID +13133851223 (ext 1020) → destination +12722001232."""

from __future__ import annotations

import base64
import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, set_env_vars, sudo_run

EXTENSION = "1020"
FROM_DID = "13133851223"
FROM_DID_E164 = f"+{FROM_DID}"
TO_NUMBER = "12722001232"
TO_E164 = f"+{TO_NUMBER}"
CAMPAIGN_ID = "6c753496-2efd-4783-aa85-eb6ec73bc512"

PHP = r"""<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$zoom = app(App\Services\Integrations\ZoomApiService::class);
$agents = app(App\Services\Communications\CommunicationsAgentService::class);
$clickToCall = app(App\Services\Communications\ZoomClickToCallService::class);

$extNum = __EXT__;
$did = __DID__;
$dest = __DEST__;
$campaignId = __CAMPAIGN__;

$ext = null;
foreach ($zoom->listExtensions(['limit' => 500])['extensions'] ?? [] as $row) {
    if ((string)($row['extension_num'] ?? '') === $extNum) {
        $ext = $row;
        break;
    }
}

if (!$ext || empty($ext['id'])) {
    echo json_encode(['ok' => false, 'error' => "Extension {$extNum} not found"]);
    exit(1);
}

$patch = $zoom->updateExtension((string)$ext['id'], [
    'status' => 'active',
    'is_dialer_agent' => true,
    'override_campaign_cid' => true,
    'caller_id_num' => $did,
    'outbound_cid_num' => $did,
    'caller_id_name' => 'Apex One',
    'outbound_cid_name' => 'Apex One',
    'campaign_id' => $campaignId,
]);

app(App\Services\Communications\MorpheusHubService::class)->bustCache();

$dialOptions = $agents->extensionDialOptions($extNum);
$normalizedDest = $clickToCall->normalizePhone($dest);
$originateDest = preg_replace('/\D/', '', $normalizedDest) ?: ltrim($normalizedDest, '+');

$payload = array_merge([
    'extension' => $extNum,
    'destination' => $originateDest,
    'timeout_sec' => (int) config('integrations.morpheus.ring_timeout', 45),
], array_filter([
    'caller_id_number' => $dialOptions['caller_id_number'] ?? null,
    'caller_id_name' => $dialOptions['caller_id_name'] ?? null,
    'campaign_id' => $dialOptions['campaign_id'] ?? null,
], fn ($v) => filled($v)));

echo json_encode([
    'ok' => true,
    'extension' => [
        'id' => $ext['id'],
        'extension_num' => $ext['extension_num'] ?? null,
        'caller_id_num' => $patch['caller_id_num'] ?? $did,
        'outbound_cid_num' => $patch['outbound_cid_num'] ?? $did,
        'campaign_id' => $patch['campaign_id'] ?? $campaignId,
        'patch_error' => $patch['error'] ?? null,
    ],
    'env' => [
        'default_caller_id' => config('integrations.communications.default_caller_id'),
        'default_outbound_did' => config('integrations.communications.default_outbound_did'),
        'default_dial_destination' => config('integrations.communications.default_dial_destination'),
        'default_campaign_id' => config('integrations.morpheus.default_campaign_id'),
    ],
    'dial_options' => $dialOptions,
    'click_to_call_payload' => $payload,
    'route_ok' => ($payload['caller_id_number'] ?? '') === $did
        && ($payload['destination'] ?? '') === $originateDest
        && ($payload['extension'] ?? '') === $extNum,
], JSON_PRETTY_PRINT);
"""


def update_local_env() -> None:
    env_path = ROOT / ".env"
    if not env_path.is_file():
        return

    text = env_path.read_text(encoding="utf-8")
    updates = {
        "COMMUNICATIONS_DEFAULT_CALLER_ID": EXTENSION,
        "COMMUNICATIONS_DEFAULT_OUTBOUND_DID": FROM_DID_E164,
        "COMMUNICATIONS_DEFAULT_DIAL_DESTINATION": TO_E164,
        "MORPHEUS_DEFAULT_CAMPAIGN_ID": CAMPAIGN_ID,
    }

    for key, val in updates.items():
        pattern = re.compile(r"^" + re.escape(key) + r"=.*$", re.M)
        line = f"{key}={val}"
        text = pattern.sub(line, text) if pattern.search(text) else text.rstrip() + "\n" + line + "\n"

    env_path.write_text(text, encoding="utf-8")


def main() -> int:
    print(f"Setting outbound route: {FROM_DID_E164} (ext {EXTENSION}) -> {TO_E164}")

    php = (
        PHP.replace("__EXT__", json.dumps(EXTENSION))
        .replace("__DID__", json.dumps(FROM_DID))
        .replace("__DEST__", json.dumps(TO_E164))
        .replace("__CAMPAIGN__", json.dumps(CAMPAIGN_ID))
    )
    encoded = base64.b64encode(php.encode()).decode()

    ssh = connect()

    set_env_vars(
        ssh,
        {
            "COMMUNICATIONS_DEFAULT_CALLER_ID": EXTENSION,
            "COMMUNICATIONS_DEFAULT_OUTBOUND_DID": FROM_DID_E164,
            "COMMUNICATIONS_DEFAULT_DIAL_DESTINATION": TO_E164,
            "MORPHEUS_DEFAULT_CAMPAIGN_ID": CAMPAIGN_ID,
            "MORPHEUS_WEBPHONE_AUTO_ANSWER": "true",
        },
    )

    sudo_run(
        ssh,
        f"cd {REMOTE_APP} && php artisan config:clear && php artisan config:cache && php artisan view:clear",
    )

    raw = sudo_run(
        ssh,
        f"cd {REMOTE_APP} && echo {encoded} | base64 -d | sudo -u www-data php",
    )
    print(raw)

    result = json.loads(raw)
    ssh.close()

    update_local_env()

    if not result.get("ok"):
        print("FAILED:", result.get("error"))
        return 1

    if not result.get("route_ok"):
        print("WARNING: click-to-call payload does not match expected route")
        print(json.dumps(result.get("click_to_call_payload"), indent=2))
        return 1

    print("Route configured successfully.")
    print(json.dumps(result.get("click_to_call_payload"), indent=2))
    print("Hard refresh CRM -> Connect line on ext 1020 -> Call (prefilled +12722001232).")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
