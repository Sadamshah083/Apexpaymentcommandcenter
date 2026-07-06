#!/usr/bin/env python3
"""Test SIP REGISTER digest auth for extension 1020 (reads password from production .env)."""

from __future__ import annotations

import base64
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run

REMOTE_SCRIPT = r"""
import hashlib, ssl, socket, base64, struct, os, re, pathlib

env = {}
for line in pathlib.Path("__REMOTE_APP__/.env").read_text().splitlines():
    if "=" in line and not line.strip().startswith("#"):
        k, v = line.split("=", 1)
        env[k.strip()] = v.strip()

PASSWORD = env.get("MORPHEUS_EXTENSION_PASSWORD", "")
USER = env.get("COMMUNICATIONS_DEFAULT_CALLER_ID", "1020")
DOMAIN = env.get("MORPHEUS_WEBRTC_SIP_DOMAIN", "apexone.pbx.local")
HOST = env.get("MORPHEUS_HOST", "apexone.morpheus.cx")
PORT = 7443

def md5(s):
    return hashlib.md5(s.encode()).hexdigest()

def digest_response(username, realm, password, method, uri, nonce, qop="auth", nc="00000001", cnonce="probe1234"):
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
    ctx = ssl.create_default_context()
    ctx.check_hostname = False
    ctx.verify_mode = ssl.CERT_NONE
    raw = socket.create_connection((HOST, PORT), timeout=12)
    sock = ctx.wrap_socket(raw, server_hostname=HOST)
    sock.sendall(req.encode())
    resp = sock.recv(4096).decode(errors="replace")
    if "101" not in resp.split("\r\n", 1)[0]:
        print("WS failed:", resp[:200])
        return None
    return sock

def ws_send(sock, text):
    data = text.encode()
    mask = os.urandom(4)
    frame = bytearray([0x81])
    ln = len(data)
    if ln < 126:
        frame.append(0x80 | ln)
    else:
        frame.append(0x80 | 126)
        frame.extend(struct.pack("!H", ln))
    frame.extend(mask)
    frame.extend(bytes(b ^ mask[i % 4] for i, b in enumerate(data)))
    sock.sendall(frame)

def ws_recv(sock):
    sock.settimeout(6)
    hdr = sock.recv(2)
    if len(hdr) < 2:
        return ""
    ln = hdr[1] & 0x7f
    if ln == 126:
        ln = struct.unpack("!H", sock.recv(2))[0]
    payload = sock.recv(ln)
    return payload.decode(errors="replace")

def try_user(sock, user):
    branch = "z9hG4bK" + user
    reg = (
        f"REGISTER sip:{DOMAIN} SIP/2.0\r\n"
        f"Via: SIP/2.0/WSS {HOST};branch={branch}\r\n"
        "Max-Forwards: 70\r\n"
        f"To: <sip:{user}@{DOMAIN}>\r\n"
        f"From: <sip:{user}@{DOMAIN}>;tag=t{user}\r\n"
        f"Call-ID: probe-{user}\r\n"
        "CSeq: 1 REGISTER\r\n"
        f"Contact: <sip:{user}@invalid;transport=ws>;expires=120\r\n"
        "Expires: 120\r\n"
        "Content-Length: 0\r\n\r\n"
    )
    ws_send(sock, reg)
    r1 = ws_recv(sock)
    m = re.search(r"WWW-Authenticate:\s*Digest\s+(.*)", r1, re.I)
    if not m:
        print(f"--- {user}: no challenge ---")
        print(r1[:400])
        return
    parts = {}
    for key, val in re.findall(r'(\w+)="([^"]+)"', m.group(1)):
        parts[key] = val
    realm = parts.get("realm", DOMAIN)
    nonce = parts["nonce"]
    uri = f"sip:{DOMAIN}"
    qop = parts.get("qop", "auth").split(",")[0] if parts.get("qop") else None
    cnonce = "a1b2c3d4"
    nc = "00000001"
    resp_hash = digest_response(user, realm, PASSWORD, "REGISTER", uri, nonce, qop, nc, cnonce)
    auth_hdr = (
        f'Digest username="{user}", realm="{realm}", nonce="{nonce}", uri="{uri}", '
        f'response="{resp_hash}", algorithm=MD5'
    )
    if qop:
        auth_hdr += f', qop={qop}, nc={nc}, cnonce="{cnonce}"'
    reg2 = (
        f"REGISTER sip:{DOMAIN} SIP/2.0\r\n"
        f"Via: SIP/2.0/WSS {HOST};branch={branch}2\r\n"
        "Max-Forwards: 70\r\n"
        f"To: <sip:{user}@{DOMAIN}>\r\n"
        f"From: <sip:{user}@{DOMAIN}>;tag=t{user}\r\n"
        f"Call-ID: probe-{user}\r\n"
        "CSeq: 2 REGISTER\r\n"
        f"Contact: <sip:{user}@invalid;transport=ws>;expires=120\r\n"
        "Expires: 120\r\n"
        f"Authorization: {auth_hdr}\r\n"
        "Content-Length: 0\r\n\r\n"
    )
    ws_send(sock, reg2)
    r2 = ws_recv(sock)
    status = r2.split("\r\n", 1)[0] if r2 else "empty"
    print(f"--- {user}@{DOMAIN} => {status}")

if not PASSWORD:
    print("ERROR: MORPHEUS_EXTENSION_PASSWORD missing")
    raise SystemExit(1)

sock = ws_connect()
if sock:
    try_user(sock, USER)
    sock.close()
""".replace("__REMOTE_APP__", REMOTE_APP)


def main() -> int:
    ssh = connect()
    enc = base64.b64encode(REMOTE_SCRIPT.encode()).decode()
    print(sudo_run(ssh, f"echo {enc} | base64 -d | python3", check=False))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
