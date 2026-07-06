#!/usr/bin/env python3
"""Verify extension 1020 webphone config, SIP REGISTER, and build assets on production."""

from __future__ import annotations

import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run

PHP = r"""<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$user = App\Models\User::where('name', 'admin_super_91a')->first() ?: App\Models\User::first();
$workspace = app(App\Services\Workspace\WorkspaceContextService::class)->resolveActiveWorkspace($user);
$webphone = app(App\Services\Communications\CommunicationsWebphoneService::class);
$agents = app(App\Services\Communications\CommunicationsAgentService::class);

$cfg = $webphone->configFor($user, $workspace, '1020', 'admin.');
$canDial = $agents->userCanDialFrom($user, $workspace, '1020', 'admin.');

echo json_encode([
    'env_caller_id' => config('integrations.communications.default_caller_id'),
    'env_sip_domain' => config('integrations.morpheus.webrtc_sip_domain'),
    'env_password_set' => filled(config('integrations.morpheus.extension_password')),
    'can_dial_1020' => $canDial,
    'dial_options' => $agents->extensionDialOptions('1020'),
    'webphone' => $cfg ? [
        'extension' => $cfg['extension'] ?? null,
        'auth_user' => $cfg['auth_user'] ?? null,
        'sip_user' => $cfg['sip_user'] ?? null,
        'domain' => $cfg['domain'] ?? null,
        'dial_domain' => $cfg['dial_domain'] ?? null,
        'wss_url' => $cfg['wss_url'] ?? null,
        'outbound_caller_id' => $cfg['outbound_caller_id'] ?? null,
        'has_password' => filled($cfg['password'] ?? null),
    ] : null,
], JSON_PRETTY_PRINT);
"""

SIP_PY = r"""
import hashlib, ssl, socket, base64, struct, os, re, json

PASSWORD = __PASSWORD__
DOMAIN = "apexone.pbx.local"
HOST = "apexone.morpheus.cx"
PORT = 7443
USER = "1020"

def md5(s):
    return hashlib.md5(s.encode()).hexdigest()

def digest_response(username, realm, password, method, uri, nonce, qop='auth', nc='00000001', cnonce='probe1234'):
    ha1 = md5(f"{username}:{realm}:{password}")
    ha2 = md5(f"{method}:{uri}")
    if qop:
        return md5(f"{ha1}:{nonce}:{nc}:{cnonce}:{qop}:{ha2}")
    return md5(f"{ha1}:{nonce}:{ha2}")

def ws_connect():
    key = base64.b64encode(os.urandom(16)).decode()
    req = (
        f"GET /ws HTTP/1.1\r\nHost: {HOST}:{PORT}\r\n"
        "Upgrade: websocket\r\nConnection: Upgrade\r\n"
        "Sec-WebSocket-Version: 13\r\n"
        f"Sec-WebSocket-Key: {key}\r\n"
        "Origin: https://crm.apexonepayments.com\r\n\r\n"
    )
    ctx = ssl.create_default_context(); ctx.check_hostname=False; ctx.verify_mode=ssl.CERT_NONE
    raw = socket.create_connection((HOST, PORT), timeout=12)
    sock = ctx.wrap_socket(raw, server_hostname=HOST)
    sock.sendall(req.encode())
    resp = sock.recv(4096).decode(errors='replace')
    if '101' not in resp.split('\r\n',1)[0]:
        print(json.dumps({'ok': False, 'error': 'websocket_failed', 'resp': resp[:200]}))
        return None
    return sock

def ws_send(sock, text):
    data = text.encode()
    mask = os.urandom(4)
    masked = bytes(b ^ mask[i % 4] for i, b in enumerate(data))
    frame = bytes([0x81, 0x80 | len(data)]) + mask + masked
    sock.sendall(frame)

def ws_recv(sock):
    hdr = sock.recv(2)
    if len(hdr) < 2:
        return ''
    ln = hdr[1] & 0x7f
    if ln == 126:
        ln = struct.unpack('!H', sock.recv(2))[0]
    elif ln == 127:
        ln = struct.unpack('!Q', sock.recv(8))[0]
    if hdr[1] & 0x80:
        sock.recv(4)
    payload = sock.recv(ln)
    return payload.decode(errors='replace')

sock = ws_connect()
if not sock:
    raise SystemExit(0)

branch = 'z9hG4bKprobe'
uri = f"sip:{DOMAIN}"
reg1 = (
    f"REGISTER sip:{DOMAIN} SIP/2.0\r\n"
    f"Via: SIP/2.0/WSS {HOST};branch={branch}\r\n"
    "Max-Forwards: 70\r\n"
    f"To: <sip:{USER}@{DOMAIN}>\r\n"
    f"From: <sip:{USER}@{DOMAIN}>;tag=t{USER}\r\n"
    f"Call-ID: probe-{USER}\r\n"
    "CSeq: 1 REGISTER\r\n"
    f"Contact: <sip:{USER}@invalid;transport=ws>;expires=120\r\n"
    "Expires: 120\r\n"
    "Content-Length: 0\r\n\r\n"
)
ws_send(sock, reg1)
r1 = ws_recv(sock)
m = re.search(r'WWW-Authenticate:\s*Digest\s+(.+)', r1, re.I)
if not m:
    print(json.dumps({'ok': False, 'error': 'no_challenge', 'status': r1.split(chr(10),1)[0]}))
    raise SystemExit(0)

params = {}
for part in re.findall(r'(\w+)="?([^",\s]+)"?', m.group(1)):
    params[part[0]] = part[1]
realm = params.get('realm', DOMAIN)
nonce = params.get('nonce', '')
qop = params.get('qop', 'auth')
nc = '00000001'
cnonce = 'probe1234'
resp_hash = digest_response(USER, realm, PASSWORD, 'REGISTER', uri, nonce, qop, nc, cnonce)
auth_hdr = (
    f'Digest username="{USER}", realm="{realm}", nonce="{nonce}", uri="{uri}", '
    f'response="{resp_hash}", algorithm=MD5'
)
if qop:
    auth_hdr += f', qop={qop}, nc={nc}, cnonce="{cnonce}"'
reg2 = (
    f"REGISTER sip:{DOMAIN} SIP/2.0\r\n"
    f"Via: SIP/2.0/WSS {HOST};branch={branch}2\r\n"
    "Max-Forwards: 70\r\n"
    f"To: <sip:{USER}@{DOMAIN}>\r\n"
    f"From: <sip:{USER}@{DOMAIN}>;tag=t{USER}\r\n"
    f"Call-ID: probe-{USER}\r\n"
    "CSeq: 2 REGISTER\r\n"
    f"Contact: <sip:{USER}@invalid;transport=ws>;expires=120\r\n"
    "Expires: 120\r\n"
    f"Authorization: {auth_hdr}\r\n"
    "Content-Length: 0\r\n\r\n"
)
ws_send(sock, reg2)
r2 = ws_recv(sock)
status = r2.split('\r\n',1)[0] if r2 else 'empty'
print(json.dumps({'ok': '200' in status, 'status': status}))
sock.close()
"""


def main() -> int:
    import base64

    ssh = connect()

    print("=== Webphone config (1020) ===")
    encoded = base64.b64encode(PHP.encode()).decode()
    raw = sudo_run(ssh, f"cd {REMOTE_APP} && echo {encoded} | base64 -d | sudo -u www-data php")
    print(raw)

    password = ""
    for line in sudo_run(ssh, f"grep '^MORPHEUS_EXTENSION_PASSWORD=' {REMOTE_APP}/.env").splitlines():
        if "=" in line:
            password = line.split("=", 1)[1].strip()
            break

    if password:
        print("\n=== SIP REGISTER digest (1020) ===")
        sip_script = SIP_PY.replace("__PASSWORD__", json.dumps(password))
        sip_out = sudo_run(ssh, f"python3 -c {json.dumps(sip_script)}")
        print(sip_out)
    else:
        print("SKIP SIP test: no MORPHEUS_EXTENSION_PASSWORD on server")

    print("\n=== Build assets ===")
    manifest_raw = sudo_run(ssh, f"cat {REMOTE_APP}/public/build/manifest.json")
    manifest = json.loads(manifest_raw)
    for key in sorted(manifest):
        if "communications-dialer" in key or "communications-webphone" in key:
            file = manifest[key].get("file", "")
            url = f"https://crm.apexonepayments.com/build/{file}"
            _, o, _ = ssh.exec_command(f"curl -fsSI {url} | head -1")
            print(f"  {key} -> {o.read().decode().strip()}")

    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
