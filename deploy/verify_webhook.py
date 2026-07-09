#!/usr/bin/env python3
"""Verify Morpheus call webhook on production (answer + hangup)."""
from __future__ import annotations

import json
import sys
import uuid
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run

TEST_UUID = str(uuid.uuid4())
TEST_DEST = "2722001232"


def php_test() -> str:
    return f"""cd {REMOTE_APP} && sudo -u www-data php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();

$events = app(App\\Services\\Communications\\MorpheusCallEventService::class);
$events->watchCall("{TEST_UUID}", "1020", "{TEST_DEST}");

$controller = app(App\\Http\\Controllers\\MorpheusHubController::class);

$answer = Illuminate\\Http\\Request::create(
    "/webhooks/morpheus/calls",
    "POST",
    [],
    [],
    [],
    ["CONTENT_TYPE" => "application/json"],
    json_encode([
        "event" => "call.answered",
        "call_uuid" => "{TEST_UUID}",
        "destination_number" => "+1{TEST_DEST}",
        "billsec" => 3,
        "state" => "CONNECTED",
    ])
);
$answerResp = $controller->receiveCallWebhook($answer);
$overlay = $events->hubStatusOverlay("{TEST_UUID}", "+1{TEST_DEST}");

$hangup = Illuminate\\Http\\Request::create(
    "/webhooks/morpheus/calls",
    "POST",
    [],
    [],
    [],
    ["CONTENT_TYPE" => "application/json"],
    json_encode([
        "event" => "call.hangup",
        "call_uuid" => "{TEST_UUID}",
        "destination_number" => "+1{TEST_DEST}",
        "hangup_cause" => "NORMAL_CLEARING",
        "billsec" => 12,
        "state" => "HANGUP",
    ])
);
$hangupResp = $controller->receiveCallWebhook($hangup);
$ended = $events->hubStatusOverlay("{TEST_UUID}", "+1{TEST_DEST}");

echo json_encode([
    "answer_http" => $answerResp->getStatusCode(),
    "answer_body" => json_decode($answerResp->getContent(), true),
    "connected_overlay" => $overlay,
    "hangup_http" => $hangupResp->getStatusCode(),
    "hangup_body" => json_decode($hangupResp->getContent(), true),
    "ended_overlay" => $ended,
], JSON_PRETTY_PRINT);
'"""


def main() -> int:
    ssh = connect()

    print("Checking public webhook route...")
    route_out = sudo_run(
        ssh,
        f"cd {REMOTE_APP} && sudo -u www-data php artisan route:list --path=webhooks/morpheus",
        check=False,
    )
    print(route_out)

    print("GET webhook health (browser-safe)...")
    get_out = sudo_run(
        ssh,
        "curl -sS https://crm.apexonepayments.com/webhooks/morpheus/calls",
        check=False,
    )
    print(get_out)

    print("POST webhook via curl (no auth)...")
    curl_out = sudo_run(
        ssh,
        (
            "curl -sS -o /tmp/morpheus_webhook_test.json -w '%{http_code}' "
            "-X POST https://crm.apexonepayments.com/webhooks/morpheus/calls "
            "-H 'Content-Type: application/json' "
            f"-d '{{\"event\":\"call.answered\",\"call_uuid\":\"{TEST_UUID}\",\"destination_number\":\"+1{TEST_DEST}\",\"state\":\"CONNECTED\"}}'"
        ),
        check=False,
    )
    print("HTTP:", curl_out.strip())
    body = sudo_run(ssh, "cat /tmp/morpheus_webhook_test.json", check=False)
    print("Body:", body)

    print("Running in-app webhook simulation...")
    sim = sudo_run(ssh, php_test(), check=False)
    print(sim)

    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
