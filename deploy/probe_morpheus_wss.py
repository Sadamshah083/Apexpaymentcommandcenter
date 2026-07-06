#!/usr/bin/env python3
"""Probe Morpheus SIP WebSocket upgrade on port 7443."""

from __future__ import annotations

import base64
import json
import ssl
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import connect, sudo_run

PHP = """<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
echo json_encode([
    "sip_host" => config("integrations.morpheus.sip_host"),
    "host" => config("integrations.morpheus.host"),
    "wss_url" => config("integrations.morpheus.sip_wss_url"),
], JSON_PRETTY_PRINT);
"""


def main() -> int:
    ssh = connect()

    encoded = base64.b64encode(PHP.encode()).decode()
    cfg = sudo_run(
        ssh,
        f"cd /var/www/apexone && echo {encoded} | base64 -d | sudo -u www-data php",
        check=False,
    )
    print("=== APP CONFIG ===")
    print(cfg)

    ws_test = r"""
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
    print("=== WS UPGRADE (7443/ws) ===")
    print(sudo_run(ssh, ws_test, check=False))

    cert = sudo_run(
        ssh,
        "echo | openssl s_client -connect apexone.morpheus.cx:7443 -servername apexone.morpheus.cx 2>/dev/null | openssl x509 -noout -subject -issuer -dates 2>/dev/null || echo cert_probe_failed",
        check=False,
    )
    print("=== TLS CERT (7443) ===")
    print(cert)

    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
