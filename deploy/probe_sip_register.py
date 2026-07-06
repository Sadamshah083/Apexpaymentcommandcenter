#!/usr/bin/env python3
"""Probe Morpheus users/extensions and webphone config on production."""

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
$click = app(App\Services\Communications\ZoomClickToCallService::class);

$users = $zoom->listUsers(['limit' => 100])['users'] ?? [];
$exts = $zoom->listExtensions(['limit' => 100])['extensions'] ?? [];

$babar = null;
foreach ($users as $u) {
    if (strcasecmp((string)($u['username'] ?? ''), 'babar') === 0) {
        $babar = $zoom->getUser((string)$u['id']);
        break;
    }
}

$ext1001 = null;
foreach ($exts as $e) {
    if ((string)($e['extension_num'] ?? '') === '1001') {
        $ext1001 = $e;
        break;
    }
}

echo json_encode([
    'env' => [
        'sip_auth_user' => config('integrations.morpheus.sip_auth_user'),
        'webrtc_domain' => $click->webrtcSipDomain(),
        'public_host' => $click->publicSipHost(),
        'sip_host' => config('integrations.morpheus.sip_host'),
        'wss_url' => config('integrations.morpheus.sip_wss_url'),
        'password_len' => strlen((string)config('integrations.morpheus.extension_password')),
    ],
    'babar' => $babar,
    'ext_1001' => $ext1001 ? [
        'id' => $ext1001['id'],
        'extension_num' => $ext1001['extension_num'],
        'user_id' => $ext1001['user_id'] ?? null,
        'status' => $ext1001['status'] ?? null,
    ] : null,
    'users_sample' => array_map(fn($u) => [
        'username' => $u['username'] ?? null,
        'id' => $u['id'] ?? null,
        'status' => $u['status'] ?? null,
    ], array_slice($users, 0, 15)),
    'extensions_sample' => array_map(fn($e) => [
        'extension_num' => $e['extension_num'] ?? null,
        'user_id' => $e['user_id'] ?? null,
        'status' => $e['status'] ?? null,
    ], array_slice($exts, 0, 15)),
], JSON_PRETTY_PRINT);
"""

WS_TEST = r"""
python3 - <<'PY'
import ssl, socket, hashlib, base64, re, struct, os

def ws_connect(host, port, path, origin):
    key = base64.b64encode(os.urandom(16)).decode()
    req = (
        f"GET {path} HTTP/1.1\r\n"
        f"Host: {host}:{port}\r\n"
        "Upgrade: websocket\r\n"
        "Connection: Upgrade\r\n"
        "Sec-WebSocket-Version: 13\r\n"
        f"Sec-WebSocket-Key: {key}\r\n"
        f"Origin: {origin}\r\n\r\n"
    )
    ctx = ssl.create_default_context()
    ctx.check_hostname = False
    ctx.verify_mode = ssl.CERT_NONE
    raw = socket.create_connection((host, port), timeout=12)
    sock = ctx.wrap_socket(raw, server_hostname=host)
    sock.sendall(req.encode())
    resp = sock.recv(4096).decode(errors='replace')
    if '101' not in resp.split('\r\n', 1)[0]:
        print('WS_FAIL', resp.split('\r\n\r\n',1)[0][:300])
        return None
    return sock

def ws_send_text(sock, text):
    data = text.encode()
    mask = os.urandom(4)
    frame = bytearray([0x81])
    ln = len(data)
    if ln < 126:
        frame.append(0x80 | ln)
    else:
        frame.append(0x80 | 126)
        frame.extend(struct.pack('!H', ln))
    frame.extend(mask)
    frame.extend(bytes(b ^ mask[i % 4] for i, b in enumerate(data)))
    sock.sendall(frame)

def ws_recv(sock, timeout=5):
    sock.settimeout(timeout)
    try:
        hdr = sock.recv(2)
        if len(hdr) < 2:
            return ''
        b1, b2 = hdr[0], hdr[1]
        masked = bool(b2 & 0x80)
        ln = b2 & 0x7f
        if ln == 126:
            ln = struct.unpack('!H', sock.recv(2))[0]
        elif ln == 127:
            ln = struct.unpack('!Q', sock.recv(8))[0]
        mask = sock.recv(4) if masked else b''
        payload = sock.recv(ln)
        if masked:
            payload = bytes(b ^ mask[i % 4] for i, b in enumerate(payload))
        return payload.decode(errors='replace')
    except Exception as e:
        return f'TIMEOUT:{e}'

host = 'apexone.morpheus.cx'
sock = ws_connect(host, 7443, '/ws', 'https://crm.apexonepayments.com')
if not sock:
    raise SystemExit(1)

domain = 'apexone.pbx.local'
user = 'babar'
call_id = 'probe-call-id-abc'
branch = 'z9hG4bKprobe'
register = (
    f"REGISTER sip:{domain} SIP/2.0\r\n"
    f"Via: SIP/2.0/WSS {host};branch={branch}\r\n"
    f"Max-Forwards: 70\r\n"
    f"To: <sip:{user}@{domain}>\r\n"
    f"From: <sip:{user}@{domain}>;tag=probe\r\n"
    f"Call-ID: {call_id}\r\n"
    "CSeq: 1 REGISTER\r\n"
    "Contact: <sip:probe@invalid;transport=ws>;expires=120\r\n"
    "Expires: 120\r\n"
    "Allow: INVITE, ACK, CANCEL, BYE, OPTIONS\r\n"
    "Content-Length: 0\r\n\r\n"
)
ws_send_text(sock, register)
resp1 = ws_recv(sock)
print('=== REGISTER babar (no auth) ===')
print(resp1[:1200] if resp1 else '(empty)')

# try extension 1001
user2 = '1001'
register2 = register.replace(f'<sip:{user}@{domain}>', f'<sip:{user2}@{domain}>').replace(f'<sip:{user}@{domain}>', f'<sip:{user2}@{domain}>')
register2 = (
    f"REGISTER sip:{domain} SIP/2.0\r\n"
    f"Via: SIP/2.0/WSS {host};branch={branch}2\r\n"
    f"Max-Forwards: 70\r\n"
    f"To: <sip:{user2}@{domain}>\r\n"
    f"From: <sip:{user2}@{domain}>;tag=probe2\r\n"
    f"Call-ID: probe-call-id-1001\r\n"
    "CSeq: 1 REGISTER\r\n"
    "Contact: <sip:probe2@invalid;transport=ws>;expires=120\r\n"
    "Expires: 120\r\n"
    "Allow: INVITE, ACK, CANCEL, BYE, OPTIONS\r\n"
    "Content-Length: 0\r\n\r\n"
)
ws_send_text(sock, register2)
resp2 = ws_recv(sock)
print('=== REGISTER 1001 (no auth) ===')
print(resp2[:1200] if resp2 else '(empty)')
sock.close()
PY
"""


def main() -> int:
    ssh = connect()
    enc = base64.b64encode(PHP.encode()).decode()
    print("=== MORPHEUS STATE ===")
    print(sudo_run(ssh, f"cd /var/www/apexone && echo {enc} | base64 -d | sudo -u www-data php"))
    print("\n=== RAW SIP PROBE ===")
    print(sudo_run(ssh, WS_TEST, check=False))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
