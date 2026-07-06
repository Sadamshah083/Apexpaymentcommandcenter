#!/usr/bin/env python3
"""Diagnose Morpheus browser phone SIP registration blockers."""

from __future__ import annotations

import base64
import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import connect, sudo_run

PHP = r"""<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$zoom = app(App\Services\Integrations\ZoomApiService::class);
$agents = app(App\Services\Communications\CommunicationsAgentService::class);
$webphone = app(App\Services\Communications\CommunicationsWebphoneService::class);
$click = app(App\Services\Communications\ZoomClickToCallService::class);

$extNum = '1001';
$ext = collect(app(App\Services\Communications\MorpheusHubService::class)->extensions())
    ->first(fn ($e) => (string)($e['extension_num'] ?? '') === $extNum);

$pwdConfigured = filled(config('integrations.morpheus.extension_password'))
    || filled(env('MORPHEUS_EXTENSION_PASSWORD'));

$out = [
    'sip_host' => config('integrations.morpheus.sip_host'),
    'public_sip_host' => $click->publicSipHost(),
    'wss_url' => config('integrations.morpheus.sip_wss_url'),
    'password_configured' => $pwdConfigured,
    'password_length' => strlen((string)(config('integrations.morpheus.extension_password') ?: env('MORPHEUS_EXTENSION_PASSWORD', ''))),
    'extension' => $ext ? [
        'id' => $ext['id'] ?? null,
        'extension_num' => $ext['extension_num'] ?? null,
        'status' => $ext['status'] ?? null,
        'caller_id_num' => $ext['caller_id_num'] ?? null,
        'user_id' => $ext['user_id'] ?? null,
    ] : null,
    'dial_options' => $agents->extensionDialOptions($extNum),
];

// Try password sync (no secret in output)
if ($ext && !empty($ext['id']) && $pwdConfigured) {
    $password = (string)(config('integrations.morpheus.extension_password') ?: env('MORPHEUS_EXTENSION_PASSWORD'));
    $patch = $zoom->updateExtension((string)$ext['id'], [
        'password' => $password,
        'status' => 'active',
        'is_dialer_agent' => true,
        'override_campaign_cid' => true,
    ]);
    $out['extension_password_sync'] = [
        'ok' => !isset($patch['error']) || isset($patch['id']),
        'error' => $patch['error'] ?? null,
        'id' => $patch['id'] ?? null,
    ];
}

echo json_encode($out, JSON_PRETTY_PRINT);
"""

WS_CHECKS = r"""
set -e
echo '=== CRM proxy WS upgrade ==='
python3 - <<'PY'
import ssl, socket
host = "crm.apexonepayments.com"
path = "/morpheus-ws/ws"
key = "dGhlIHNhbXBsZSBub25jZQ=="
req = (
    f"GET {path} HTTP/1.1\r\n"
    f"Host: {host}\r\n"
    "Upgrade: websocket\r\n"
    "Connection: Upgrade\r\n"
    "Sec-WebSocket-Version: 13\r\n"
    f"Sec-WebSocket-Key: {key}\r\n"
    "Origin: https://crm.apexonepayments.com\r\n"
    "\r\n"
)
ctx = ssl.create_default_context()
raw = socket.create_connection((host, 443), timeout=10)
sock = ctx.wrap_socket(raw, server_hostname=host)
sock.sendall(req.encode())
resp = sock.recv(4096).decode(errors="replace")
print(resp.split("\r\n\r\n", 1)[0])
sock.close()
PY

echo '=== Direct Morpheus 7443 WS upgrade ==='
python3 - <<'PY'
import ssl, socket
host = "apexone.morpheus.cx"
port = 7443
path = "/ws"
key = "dGhlIHNhbXBsZSBub25jZQ=="
req = (
    f"GET {path} HTTP/1.1\r\n"
    f"Host: {host}:{port}\r\n"
    "Upgrade: websocket\r\n"
    "Connection: Upgrade\r\n"
    "Sec-WebSocket-Version: 13\r\n"
    f"Sec-WebSocket-Key: {key}\r\n"
    "Origin: https://crm.apexonepayments.com\r\n"
    "\r\n"
)
ctx = ssl.create_default_context()
ctx.check_hostname = False
ctx.verify_mode = ssl.CERT_NONE
raw = socket.create_connection((host, port), timeout=10)
sock = ctx.wrap_socket(raw, server_hostname=host)
sock.sendall(req.encode())
resp = sock.recv(4096).decode(errors="replace")
print(resp.split("\r\n\r\n", 1)[0])
sock.close()
PY
"""


def main() -> int:
    ssh = connect()
    encoded = base64.b64encode(PHP.encode()).decode()
    raw = sudo_run(
        ssh,
        f"cd /var/www/apexone && echo {encoded} | base64 -d | sudo -u www-data php",
        check=False,
    )
    print("=== PHP DIAGNOSTIC ===")
    print(raw)

    print(sudo_run(ssh, WS_CHECKS, check=False))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
