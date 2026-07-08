#!/usr/bin/env python3
"""Live test Morpheus Call-Control APIs: list calls, get call, click-to-call."""
from __future__ import annotations

import base64
import json
import os
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run

EXTENSION = os.environ.get("MORPHEUS_TEST_EXTENSION", "1001")
DESTINATION = os.environ.get("MORPHEUS_TEST_DESTINATION", "12722001232")
CALLER_ID = os.environ.get("MORPHEUS_TEST_CALLER_ID", "13133851223")
CAMPAIGN_ID = os.environ.get(
    "MORPHEUS_DEFAULT_CAMPAIGN_ID",
    "6c753496-2efd-4783-aa85-eb6ec73bc512",
)
PLACE_CALL = os.environ.get("MORPHEUS_PLACE_TEST_CALL", "1") == "1"

PHP = r"""<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Http;

$host = (string) config('integrations.morpheus.host');
$key = (string) config('integrations.morpheus.api_key');
$base = "https://{$host}/api/v1/call-control";
$ext = __EXT__;
$dest = __DEST__;
$did = __DID__;
$campaign = __CAMPAIGN__;
$placeCall = __PLACE__;

$client = fn () => Http::timeout(15)
    ->acceptJson()
    ->withHeaders([
        'X-API-Key' => $key,
        'Authorization' => 'Bearer ' . $key,
    ]);

$docListFields = ['uuid', 'status', 'direction', 'phone_number', 'campaign_id', 'started_at'];
$docGetFields = ['uuid', 'status', 'direction', 'phone_number', 'campaign_id', 'started_at'];

function shapeCheck(array $row, array $expected): array {
    $present = [];
    $missing = [];
    foreach ($expected as $field) {
        if (array_key_exists($field, $row)) {
            $present[] = $field;
        } else {
            $missing[] = $field;
        }
    }
    return ['present' => $present, 'missing' => $missing, 'extra_keys' => array_values(array_diff(array_keys($row), $expected))];
}

function summarizeBody(?array $body, int $maxItems = 2): mixed {
    if (! is_array($body)) {
        return null;
    }
    if (isset($body['calls']) && is_array($body['calls'])) {
        return [
            'calls_count' => count($body['calls']),
            'sample' => array_slice($body['calls'], 0, $maxItems),
        ];
    }
    return $body;
}

$out = [
    'host' => $host,
    'api_key_prefix' => substr($key, 0, 8) . '…',
    'tests' => [],
];

// 1) GET /calls — list active calls (docs: calls:read)
$listResp = $client()->get("{$base}/calls");
$listBody = $listResp->json() ?? [];
$listCalls = is_array($listBody['calls'] ?? null) ? $listBody['calls'] : null;
$listShape = null;
if ($listCalls !== null && count($listCalls) > 0) {
    $listShape = shapeCheck($listCalls[0], $docListFields);
}
$out['tests']['list_active_calls'] = [
    'method' => 'GET',
    'path' => '/api/v1/call-control/calls',
    'required_scope' => 'calls:read',
    'http_status' => $listResp->status(),
    'matches_docs_200' => $listResp->successful() && is_array($listCalls),
    'response_summary' => summarizeBody($listBody),
    'first_call_shape' => $listShape,
    'raw_error' => $listResp->successful() ? null : ($listBody['error'] ?? $listResp->body()),
];

$callUuid = null;

// 2) POST /click-to-call (docs: calls:originate)
if ($placeCall) {
    app(App\Services\Integrations\MorpheusCircuitBreaker::class)->reset();
    Illuminate\Support\Facades\Cache::forget('integrations.morpheus.circuit_open');

    $payload = array_filter([
        'extension' => $ext,
        'destination' => preg_replace('/\D/', '', $dest),
        'caller_id_number' => preg_replace('/\D/', '', $did),
        'timeout_sec' => 30,
        'campaign_id' => $campaign,
    ], fn ($v) => $v !== null && $v !== '');

    $ctcResp = $client()->post("{$base}/click-to-call", $payload);
    $ctcBody = $ctcResp->json() ?? [];

    $out['tests']['click_to_call'] = [
        'method' => 'POST',
        'path' => '/api/v1/call-control/click-to-call',
        'required_scope' => 'calls:originate',
        'request_payload' => $payload,
        'http_status' => $ctcResp->status(),
        'matches_docs_ok' => $ctcResp->successful() && filled($ctcBody['call_uuid'] ?? null),
        'response_body' => $ctcBody,
        'raw_error' => $ctcResp->successful() ? null : ($ctcBody['error'] ?? $ctcResp->body()),
    ];

    $callUuid = (string) ($ctcBody['call_uuid'] ?? '');

    // Laravel payload shape (no second live call — compare only)
    $zoom = app(App\Services\Integrations\ZoomApiService::class);
    $agents = app(App\Services\Communications\CommunicationsAgentService::class);
    $dialOptions = $agents->extensionDialOptions($ext);
    $out['tests']['laravel_expected_payload'] = array_filter([
        'extension' => $ext,
        'destination' => $zoom->normalizeOriginateDestination($dest),
        'caller_id_number' => $zoom->normalizeOriginateCallerId($dialOptions['caller_id_number'] ?? $did),
        'timeout_sec' => 30,
        'campaign_id' => $dialOptions['campaign_id'] ?? $campaign,
    ], fn ($v) => $v !== null && $v !== '');
    $out['tests']['laravel_payload_matches_request'] = $out['tests']['laravel_expected_payload'] == $payload;

    usleep(800000);
}

// 3) GET /calls/{uuid} — retrieve single call (docs: calls:read)
if (filled($callUuid)) {
  $getResp = $client()->get("{$base}/calls/{$callUuid}");
  $getBody = $getResp->json() ?? [];
  $getShape = is_array($getBody) && $getBody !== [] ? shapeCheck($getBody, $docGetFields) : null;

  $out['tests']['retrieve_call'] = [
      'method' => 'GET',
      'path' => "/api/v1/call-control/calls/{$callUuid}",
      'required_scope' => 'calls:read',
      'http_status' => $getResp->status(),
      'matches_docs_200' => $getResp->successful(),
      'response_body' => $getBody,
      'shape_vs_docs' => $getShape,
      'raw_error' => $getResp->successful() ? null : ($getBody['error'] ?? $getResp->body()),
  ];

  // Re-list active calls to see if our call appears
  $listAfter = $client()->get("{$base}/calls");
  $afterBody = $listAfter->json() ?? [];
  $afterCalls = is_array($afterBody['calls'] ?? null) ? $afterBody['calls'] : [];
  $found = false;
  foreach ($afterCalls as $row) {
      if ((string) ($row['uuid'] ?? '') === $callUuid) {
          $found = true;
          $out['tests']['call_in_active_list'] = [
              'found' => true,
              'call' => $row,
              'shape_vs_docs' => shapeCheck($row, $docListFields),
          ];
          break;
      }
  }
  if (! $found) {
      $out['tests']['call_in_active_list'] = [
          'found' => false,
          'active_calls_count' => count($afterCalls),
          'note' => 'Call may have ended quickly or live API uses different fields than docs.',
      ];
  }

  // Laravel getCall (with CDR fallback)
  $zoom = $zoom ?? app(App\Services\Integrations\ZoomApiService::class);
  $snapshot = $zoom->getCall($callUuid);
  $out['tests']['laravel_get_call'] = [
      'found' => $snapshot !== null,
      'snapshot' => $snapshot,
  ];
} else {
  $out['tests']['retrieve_call'] = [
      'skipped' => true,
      'reason' => 'No call_uuid from click-to-call',
  ];
}

// 4) Auth failure shape probe (docs: 401 with {error: string})
$badResp = Http::timeout(8)->acceptJson()->withHeaders(['X-API-Key' => 'invalid-key-test'])
    ->get("{$base}/calls");
$badBody = $badResp->json() ?? [];
$out['tests']['auth_invalid_key'] = [
    'http_status' => $badResp->status(),
    'matches_docs_401' => $badResp->status() === 401,
    'has_error_field' => isset($badBody['error']),
    'response_body' => $badBody,
];

$passed = ($out['tests']['list_active_calls']['matches_docs_200'] ?? false)
    && (! $placeCall || ($out['tests']['click_to_call']['matches_docs_ok'] ?? false));

$out['summary'] = [
    'all_passed' => (bool) $passed,
    'list_calls_ok' => $out['tests']['list_active_calls']['matches_docs_200'] ?? false,
    'click_to_call_ok' => $out['tests']['click_to_call']['matches_docs_ok'] ?? (!$placeCall),
    'retrieve_call_ok' => ($out['tests']['retrieve_call']['matches_docs_200'] ?? false)
        || ($out['tests']['laravel_get_call']['found'] ?? false),
    'call_uuid' => $callUuid ?: null,
];

echo json_encode($out, JSON_PRETTY_PRINT);
"""


def main() -> int:
    php = (
        PHP.replace("__EXT__", json.dumps(EXTENSION))
        .replace("__DEST__", json.dumps(DESTINATION))
        .replace("__DID__", json.dumps(CALLER_ID))
        .replace("__CAMPAIGN__", json.dumps(CAMPAIGN_ID))
        .replace("__PLACE__", "true" if PLACE_CALL else "false")
    )

    # Fix invalid PHP that may have been in older versions
    php = php.replace(
        "&& (($out['tests']['click_to_call']['matches_docs_ok'] ?? true) if ! $placeCall else ($out['tests']['click_to_call']['matches_docs_ok'] ?? false));",
        "&& (! $placeCall || ($out['tests']['click_to_call']['matches_docs_ok'] ?? false));",
    )

    ssh = connect()
    enc = base64.b64encode(php.encode()).decode()
    out = sudo_run(ssh, f"cd {REMOTE_APP} && echo {enc} | base64 -d | sudo -u www-data php")
    ssh.close()

    data = json.loads(out)
    print(json.dumps(data, indent=2))

    summary = data.get("summary") or {}
    if summary.get("all_passed"):
        print("\nAll Morpheus Call-Control API tests passed.")
        return 0

    print("\nSome tests failed — see details above.", file=sys.stderr)
    return 1


if __name__ == "__main__":
    raise SystemExit(main())
