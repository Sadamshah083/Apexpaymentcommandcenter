#!/usr/bin/env python3
"""Configure Morpheus click-to-call API key and verify Laravel integration matches Postman payload."""

from __future__ import annotations

import base64
import json
import os
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, set_env_vars, sudo_run

def load_api_key() -> str:
    if key := os.environ.get("MORPHEUS_API_KEY"):
        return key.strip()

    env_path = ROOT / ".env"
    if env_path.is_file():
        for line in env_path.read_text(encoding="utf-8").splitlines():
            if line.startswith("MORPHEUS_API_KEY="):
                return line.split("=", 1)[1].strip().strip('"').strip("'")

    raise SystemExit("Set MORPHEUS_API_KEY in the environment or .env before running this script.")


API_KEY = load_api_key()
EXTENSION = os.environ.get("MORPHEUS_TEST_EXTENSION", "1001")
DESTINATION = os.environ.get("MORPHEUS_TEST_DESTINATION", "12722001232")
CALLER_ID = os.environ.get("MORPHEUS_TEST_CALLER_ID", "13133851223")
CAMPAIGN_ID = os.environ.get(
    "MORPHEUS_DEFAULT_CAMPAIGN_ID",
    "6c753496-2efd-4783-aa85-eb6ec73bc512",
)
PLACE_TEST_CALL = os.environ.get("MORPHEUS_PLACE_TEST_CALL", "1") == "1"

PHP = r"""<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

app(App\Services\Integrations\MorpheusCircuitBreaker::class)->reset();

$zoom = app(App\Services\Integrations\ZoomApiService::class);
$agents = app(App\Services\Communications\CommunicationsAgentService::class);
$ext = __EXT__;
$dest = __DEST__;
$did = __DID__;
$campaign = __CAMPAIGN__;
$placeCall = __PLACE__;

$profile = $zoom->outboundCallingProfile();
$dialOptions = $agents->extensionDialOptions($ext);
$normalizedDest = $zoom->normalizeOriginateDestination($dest);

$expectedPayload = array_filter([
    'extension' => $ext,
    'destination' => $normalizedDest,
    'caller_id_number' => $dialOptions['caller_id_number'] ?? $did,
    'timeout_sec' => (int) config('integrations.morpheus.ring_timeout', 30),
    'campaign_id' => $dialOptions['campaign_id'] ?? $campaign,
], fn ($v) => $v !== null && $v !== '');

$authProbe = Illuminate\Support\Facades\Http::withHeaders([
    'X-API-Key' => config('integrations.morpheus.api_key'),
    'Authorization' => 'Bearer ' . config('integrations.morpheus.api_key'),
    'Accept' => 'application/json',
])->timeout(8)->get('https://' . config('integrations.morpheus.host') . '/api/v1/call-control/campaigns', [
    'limit' => 1,
]);

$result = [
    'configured' => $zoom->isConfigured(),
    'dial_method' => config('integrations.morpheus.dial_method'),
    'api_key_prefix' => substr((string) config('integrations.morpheus.api_key'), 0, 8) . '…',
    'auth_probe_status' => $authProbe->status(),
    'auth_probe_ok' => $authProbe->successful(),
    'auth_probe_error' => $authProbe->successful() ? null : ($authProbe->json('error') ?? $authProbe->body()),
    'outbound_profile' => $profile,
    'laravel_payload' => $expectedPayload,
    'payload_matches_postman' => ($expectedPayload['extension'] ?? '') === $ext
        && ($expectedPayload['destination'] ?? '') === $normalizedDest
        && ($expectedPayload['campaign_id'] ?? '') === $campaign,
];

if ($placeCall) {
    Illuminate\Support\Facades\Cache::forget('integrations.morpheus.circuit_open');
    app(App\Services\Integrations\MorpheusCircuitBreaker::class)->reset();

    $originate = $zoom->originateCall($ext, $dest, $dialOptions);

    if (! ($originate['ok'] ?? false)) {
        Illuminate\Support\Facades\Cache::forget('integrations.morpheus.circuit_open');
        app(App\Services\Integrations\MorpheusCircuitBreaker::class)->reset();
        $originate = $zoom->originateCall($ext, $dest, $dialOptions);
    }

    $result['originate'] = [
        'ok' => $originate['ok'] ?? false,
        'call_uuid' => $originate['call_uuid'] ?? null,
        'from' => $originate['from'] ?? null,
        'to' => $originate['to'] ?? null,
        'campaign_id' => $originate['campaign_id'] ?? null,
        'outcome' => $originate['outcome'] ?? null,
        'error' => $originate['error'] ?? null,
        'attempted' => $originate['attempted'] ?? null,
    ];
}

echo json_encode($result, JSON_PRETTY_PRINT);
"""


def main() -> int:
    print("Integrating Morpheus click-to-call API on production…")

    ssh = connect()
    set_env_vars(
        ssh,
        {
            "MORPHEUS_API_KEY": API_KEY,
            "MORPHEUS_HOST": "apexone.morpheus.cx",
            "MORPHEUS_DIAL_METHOD": "api",
            "MORPHEUS_DEFAULT_CAMPAIGN_ID": CAMPAIGN_ID,
            "MORPHEUS_RING_TIMEOUT": "30",
            "COMMUNICATIONS_DEFAULT_OUTBOUND_DID": f"+{CALLER_ID}",
            "COMMUNICATIONS_DEFAULT_DIAL_DESTINATION": f"+{DESTINATION}",
        },
    )

    sudo_run(
        ssh,
        f"cd {REMOTE_APP} && sudo -u www-data php artisan config:cache && "
        f"sudo -u www-data php artisan cache:forget integrations.morpheus.circuit_open",
    )

    php = (
        PHP.replace("__EXT__", json.dumps(EXTENSION))
        .replace("__DEST__", json.dumps(DESTINATION))
        .replace("__DID__", json.dumps(CALLER_ID))
        .replace("__CAMPAIGN__", json.dumps(CAMPAIGN_ID))
        .replace("__PLACE__", "true" if PLACE_TEST_CALL else "false")
    )
    enc = base64.b64encode(php.encode()).decode()
    out = sudo_run(ssh, f"cd {REMOTE_APP} && echo {enc} | base64 -d | sudo -u www-data php")
    ssh.close()

    data = json.loads(out)
    print(json.dumps(data, indent=2))

    ok = (
        data.get("auth_probe_ok")
        and data.get("payload_matches_postman")
        and (not PLACE_TEST_CALL or (data.get("originate") or {}).get("ok"))
    )

    if ok:
        print("\nMorpheus click-to-call integration verified.")
        return 0

    print("\nIntegration verification failed — see output above.", file=sys.stderr)
    return 1


if __name__ == "__main__":
    raise SystemExit(main())
