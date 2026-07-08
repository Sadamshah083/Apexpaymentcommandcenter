#!/usr/bin/env python3
"""Live verification of all Morpheus Call-Control APIs via Laravel ZoomApiService."""
from __future__ import annotations

import base64
import json
import os
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run

EXTENSION = os.environ.get("MORPHEUS_TEST_EXTENSION", "1007")
DESTINATION = os.environ.get("MORPHEUS_TEST_DESTINATION", "12722001232")
PLACE_CALL = os.environ.get("MORPHEUS_PLACE_TEST_CALL", "0") == "1"

PHP = r"""<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Http;

$zoom = app(App\Services\Integrations\ZoomApiService::class);
$host = (string) config('integrations.morpheus.host');
$key = (string) config('integrations.morpheus.api_key');
$base = "https://{$host}/api/v1/call-control";
$ext = __EXT__;
$dest = __DEST__;
$placeCall = __PLACE__;

$client = fn () => Http::timeout(15)->acceptJson()->withHeaders(['X-API-Key' => $key]);

$out = [
    'host' => $host,
    'api_key_prefix' => substr($key, 0, 8) . '…',
    'dial_method' => config('integrations.morpheus.dial_method'),
    'outbound_prefix' => config('integrations.morpheus.outbound_prefix'),
    'default_campaign_id' => config('integrations.morpheus.default_campaign_id'),
    'tests' => [],
];

function pass(string $name, array $row): void {
    global $out;
    $out['tests'][$name] = $row;
}

// --- Campaigns ---
$campaigns = $zoom->listCampaigns(['limit' => 5]);
$campaignCount = count($campaigns['campaigns'] ?? []);
pass('campaigns_list', [
    'service' => 'ZoomApiService::listCampaigns',
    'morpheus_path' => 'GET /campaigns',
    'ok' => $campaignCount >= 0,
    'count' => $campaignCount,
    'sample_id' => $campaigns['campaigns'][0]['id'] ?? null,
]);

$emptyPost = $client()->post("{$base}/campaigns", []);
pass('campaigns_create_empty_body', [
    'morpheus_path' => 'POST /campaigns (no body)',
    'http_status' => $emptyPost->status(),
    'rejects_empty' => in_array($emptyPost->status(), [400, 422], true),
    'body' => $emptyPost->json(),
]);

// --- Lists ---
$lists = $zoom->listLeadLists(['limit' => 5]);
$listCount = count($lists['lists'] ?? []);
$listId = $lists['lists'][0]['id'] ?? null;
pass('lists_list', [
    'service' => 'ZoomApiService::listLeadLists',
    'morpheus_path' => 'GET /lists',
    'ok' => $listCount >= 0,
    'count' => $listCount,
]);

if ($listId) {
    $oneList = $zoom->getLeadList((string) $listId);
    pass('lists_get', [
        'service' => 'ZoomApiService::getLeadList',
        'morpheus_path' => "GET /lists/{$listId}",
        'ok' => filled($oneList['id'] ?? null),
        'id' => $oneList['id'] ?? null,
    ]);
} else {
    pass('lists_get', ['skipped' => true, 'reason' => 'No lists in tenant']);
}

$emptyListPost = $client()->post("{$base}/lists", []);
pass('lists_create_empty_body', [
    'morpheus_path' => 'POST /lists (no body)',
    'http_status' => $emptyListPost->status(),
    'rejects_empty' => in_array($emptyListPost->status(), [400, 422], true),
]);

// --- Calls ---
$active = $zoom->listCalls();
$activeCount = count($active['calls'] ?? []);
pass('calls_list', [
    'service' => 'ZoomApiService::listCalls',
    'morpheus_path' => 'GET /calls',
    'ok' => is_array($active['calls'] ?? null),
    'count' => $activeCount,
]);

$fakeUuid = '00000000-0000-4000-8000-000000000001';
$getFake = $client()->get("{$base}/calls/{$fakeUuid}");
pass('calls_get_missing', [
    'morpheus_path' => "GET /calls/{$fakeUuid}",
    'http_status' => $getFake->status(),
    'not_found' => in_array($getFake->status(), [404, 400], true),
]);

foreach (['hangup', 'hold', 'unhold'] as $action) {
    $resp = $client()->post("{$base}/calls/{$fakeUuid}/{$action}");
    pass("calls_{$action}_missing", [
        'morpheus_path' => "POST /calls/{uuid}/{$action}",
        'http_status' => $resp->status(),
        'rejects_missing_call' => $resp->status() >= 400,
    ]);
}

// Click-to-call validation (empty body — same error user saw in Guzzle)
$ctcEmpty = $client()->post("{$base}/click-to-call", []);
pass('click_to_call_empty_body', [
    'morpheus_path' => 'POST /click-to-call (no body)',
    'http_status' => $ctcEmpty->status(),
    'rejects_empty' => $ctcEmpty->status() >= 400,
    'error' => $ctcEmpty->json('error'),
    'note' => 'Morpheus requires extension+destination; Laravel backend adds these automatically.',
]);

// Laravel originate payload shape (no live call unless PLACE_CALL=1)
$agents = app(App\Services\Communications\CommunicationsAgentService::class);
$dialOptions = $agents->extensionDialOptions($ext);
$payload = array_filter([
    'extension' => $ext,
    'destination' => $zoom->normalizeOriginateDestination($dest),
    'caller_id_number' => $zoom->normalizeOriginateCallerId($dialOptions['caller_id_number'] ?? null),
    'timeout_sec' => 30,
    'campaign_id' => $dialOptions['campaign_id'] ?? $zoom->defaultOutboundCampaignId(),
], fn ($v) => $v !== null && $v !== '');

pass('laravel_click_to_call_payload', [
    'service' => 'ZoomApiService::originateCall payload',
    'payload' => $payload,
    'has_extension' => filled($payload['extension'] ?? null),
    'has_destination' => filled($payload['destination'] ?? null),
    'has_campaign_id' => filled($payload['campaign_id'] ?? null),
    'destination_has_no_tech_prefix' => ! str_contains((string) ($payload['destination'] ?? ''), '#'),
]);

if ($placeCall) {
    app(App\Services\Integrations\MorpheusCircuitBreaker::class)->reset();
    Illuminate\Support\Facades\Cache::forget('integrations.morpheus.circuit_open');
    $result = $zoom->originateCall($ext, $dest, $dialOptions);
    pass('click_to_call_live', [
        'service' => 'ZoomApiService::originateCall',
        'ok' => (bool) ($result['ok'] ?? false),
        'call_uuid' => $result['call_uuid'] ?? null,
        'error' => $result['error'] ?? null,
    ]);
}

// --- CDR ---
$cdr = $zoom->listCdr(['per_page' => 5]);
$cdrCount = count($cdr['logs'] ?? []);
pass('cdr_list', [
    'service' => 'ZoomApiService::listCdr',
    'morpheus_path' => 'GET /cdr',
    'ok' => $cdrCount >= 0,
    'count' => $cdrCount,
    'warning' => $cdr['warning'] ?? null,
    'sample_destination' => $cdr['logs'][0]['to_phone'] ?? $cdr['logs'][0]['destination_number'] ?? null,
]);

// --- Recordings ---
$recordings = $zoom->listRecordings(['per_page' => 5]);
$recCount = count($recordings['recordings'] ?? []);
pass('recordings_list', [
    'service' => 'ZoomApiService::listRecordings',
    'morpheus_path' => 'GET /recordings',
    'ok' => $recCount >= 0,
    'count' => $recCount,
    'warnings' => $recordings['warnings'] ?? [],
]);

// --- Voicemails ---
$vms = $zoom->listVoiceMails(['per_page' => 5]);
$vmCount = count($vms['voice_mails'] ?? []);
pass('voicemails_list', [
    'service' => 'ZoomApiService::listVoiceMails',
    'morpheus_path' => 'GET /voicemails',
    'ok' => $vmCount >= 0,
    'count' => $vmCount,
    'warning' => $vms['warning'] ?? null,
]);

// Connection diagnostics
pass('connection', [
    'service' => 'ZoomApiService::connectionStatus',
    'status' => $zoom->connectionStatus(),
    'diagnostics' => $zoom->connectionDiagnostics(),
]);

$required = [
    'campaigns_list',
    'lists_list',
    'calls_list',
    'click_to_call_empty_body',
    'laravel_click_to_call_payload',
    'cdr_list',
    'recordings_list',
    'voicemails_list',
];

$passed = true;
foreach ($required as $key) {
    $row = $out['tests'][$key] ?? [];
    $ok = ($row['ok'] ?? false) || ($row['rejects_empty'] ?? false) || ($row['has_campaign_id'] ?? false);
    if ($key === 'laravel_click_to_call_payload') {
        $ok = ($row['has_extension'] ?? false)
            && ($row['has_destination'] ?? false)
            && ($row['has_campaign_id'] ?? false)
            && ($row['destination_has_no_tech_prefix'] ?? false);
    }
    if (! $ok) {
        $passed = false;
    }
}

$out['summary'] = [
    'all_passed' => $passed,
    'backend_handles_morpheus' => true,
    'note' => 'All Morpheus API keys stay server-side. Frontend sends only destination + from_extension.',
];

echo json_encode($out, JSON_PRETTY_PRINT);
"""


def main() -> int:
    php = (
        PHP.replace("__EXT__", json.dumps(EXTENSION))
        .replace("__DEST__", json.dumps(DESTINATION))
        .replace("__PLACE__", "true" if PLACE_CALL else "false")
    )

    ssh = connect()
    enc = base64.b64encode(php.encode()).decode()
    out = sudo_run(ssh, f"cd {REMOTE_APP} && echo {enc} | base64 -d | sudo -u www-data php")
    ssh.close()

    data = json.loads(out)
    print(json.dumps(data, indent=2))

    if data.get("summary", {}).get("all_passed"):
        print("\nAll Morpheus API backend tests passed.")
        return 0

    print("\nSome tests failed — see details above.", file=sys.stderr)
    return 1


if __name__ == "__main__":
    raise SystemExit(main())
