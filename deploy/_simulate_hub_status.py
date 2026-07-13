#!/usr/bin/env python3
"""Simulate hubLiveCallStatus against Morpheus-like payloads (local PHP)."""

from __future__ import annotations

import json
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP = ROOT / "php83" / "php.exe"

SCRIPT = r'''
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Http;
use App\Services\Integrations\ZoomApiService;

config([
  "integrations.morpheus.host" => "apexone.morpheus.cx",
  "integrations.morpheus.api_key" => "test-key",
]);

$scenarios = [
  "ringing_phone_number" => [
    "calls" => [[
      "uuid" => "pstn-1",
      "phone_number" => "+12092592594",
      "status" => "ringing",
      "direction" => "outbound",
      "age_sec" => 8,
    ]],
  ],
  "active_phone_number" => [
    "calls" => [[
      "uuid" => "pstn-1",
      "phone_number" => "+12092592594",
      "status" => "active",
      "direction" => "outbound",
      "started_at" => "2026-07-12T00:00:00Z",
    ]],
  ],
  "active_with_agent_uuid_only" => [
    "calls" => [[
      "uuid" => "agent-uuid",
      "phone_number" => "+12092592594",
      "status" => "active",
      "direction" => "outbound",
    ]],
  ],
  "status_up" => [
    "calls" => [[
      "uuid" => "pstn-1",
      "phone_number" => "12092592594",
      "status" => "up",
      "direction" => "outbound",
    ]],
  ],
  "status_answered" => [
    "calls" => [[
      "uuid" => "pstn-1",
      "destination_number" => "2092592594",
      "status" => "answered",
    ]],
  ],
  "status_in_progress" => [
    "calls" => [[
      "uuid" => "pstn-1",
      "phone_number" => "+12092592594",
      "status" => "in_progress",
    ]],
  ],
  "bridged_legs" => [
    "calls" => [
      ["uuid" => "agent-uuid", "status" => "active", "bridge_uuid" => "pstn-1", "extension" => "1020"],
      ["uuid" => "pstn-1", "status" => "active", "phone_number" => "+12092592594", "bridge_uuid" => "agent-uuid"],
    ],
  ],
];

foreach ($scenarios as $name => $payload) {
  Http::fake([
    "https://apexone.morpheus.cx/api/v1/call-control/calls/agent-uuid" => Http::response([], 404),
    "https://apexone.morpheus.cx/api/v1/call-control/calls" => Http::response($payload, 200),
    "https://apexone.morpheus.cx/api/v1/call-control/cdr*" => Http::response(["cdr" => []], 200),
  ]);
  $svc = new ZoomApiService();
  // clear cache between
  $ref = new ReflectionClass($svc);
  $p = $ref->getProperty("activeCallsCache");
  $p->setAccessible(true);
  $p->setValue($svc, null);
  $p2 = $ref->getProperty("activeCallsCacheAt");
  $p2->setAccessible(true);
  $p2->setValue($svc, 0.0);

  $status = $svc->hubLiveCallStatus("agent-uuid", "+12092592594");
  echo $name . " => " . json_encode([
    "outcome" => $status["outcome"] ?? null,
    "destination_connected" => $status["destination_connected"] ?? null,
    "state" => $status["state"] ?? null,
    "to" => $status["to"] ?? null,
    "source" => $status["source"] ?? null,
  ]) . PHP_EOL;
}
'''


def main() -> int:
    result = subprocess.run(
        [str(PHP), "-r", SCRIPT],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
    )
    sys.stdout.write(result.stdout)
    sys.stderr.write(result.stderr)
    return result.returncode


if __name__ == "__main__":
    raise SystemExit(main())
