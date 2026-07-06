#!/usr/bin/env python3
"""Verify CRM Morpheus WSS proxy and webphone config."""

from __future__ import annotations

import base64
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import connect, sudo_run

PHP = """<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
$svc = app(App\\Services\\Communications\\CommunicationsWebphoneService::class);
$ref = new ReflectionClass($svc);
$m = $ref->getMethod('resolveWssUrl');
$m->setAccessible(true);
echo json_encode([
    'sip_host' => config('integrations.morpheus.sip_host'),
    'wss_url' => config('integrations.morpheus.sip_wss_url'),
    'resolved_wss' => $m->invoke($svc, app(App\\Services\\Communications\\ZoomClickToCallService::class)->publicSipHost()),
    'public_sip_host' => app(App\\Services\\Communications\\ZoomClickToCallService::class)->publicSipHost(),
], JSON_PRETTY_PRINT);
"""

WS = r"""
python3 - <<'PY'
import ssl, socket
host = "crm.apexonepayments.com"
port = 443
path = "/morpheus-ws/ws"
key = "dGhlIHNhbXBsZSBub25jZQ=="
req = (
    f"GET {path} HTTP/1.1\r\n"
    f"Host: {host}\r\n"
    "Upgrade: websocket\r\n"
    "Connection: Upgrade\r\n"
    "Sec-WebSocket-Version: 13\r\n"
    f"Sec-WebSocket-Key: {key}\r\n"
    "\r\n"
)
ctx = ssl.create_default_context()
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
    cfg = sudo_run(ssh, f"cd /var/www/apexone && echo {encoded} | base64 -d | sudo -u www-data php", check=False)
    print("=== CONFIG ===")
    print(cfg)
    print("=== PROXY WS UPGRADE ===")
    print(sudo_run(ssh, WS, check=False))
    asset = sudo_run(ssh, "curl -fsSI https://crm.apexonepayments.com/build/assets/communications-webphone-xiWV6TZv.js | head -1", check=False)
    print("=== WEBPHONE ASSET ===")
    print(asset)
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
