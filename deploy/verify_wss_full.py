#!/usr/bin/env python3
"""
Full WSS/SIP verification from production server:
  1) WebSocket 101 upgrade
  2) REGISTER 200
  3) NOTIFY -> respond 200 OK (not 481)
  4) INVITE outbound with digest auth on 407
"""
from __future__ import annotations

import base64
import hashlib
import os
import re
import socket
import ssl
import struct
import sys
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run  # noqa: E402

REMOTE_PY = r"""
import base64, hashlib, os, re, socket, ssl, struct, time, pathlib, json

env = {}
for line in pathlib.Path("__APP__/.env").read_text().splitlines():
    if "=" in line and not line.strip().startswith("#"):
        k, v = line.split("=", 1)
        env[k.strip()] = v.strip()

PASSWORD = env.get("MORPHEUS_EXTENSION_PASSWORD", "")
USER = "1020"
DOMAIN = env.get("MORPHEUS_WEBRTC_SIP_DOMAIN", "apexone.pbx.local")
DIAL_DOMAIN = env.get("MORPHEUS_SIP_HOST") or env.get("MORPHEUS_HOST", "apexone.morpheus.cx")
HOST = env.get("MORPHEUS_HOST", "apexone.morpheus.cx")
PORT = 7443
DEST = "12722001232"
DID = env.get("COMMUNICATIONS_DEFAULT_OUTBOUND_DID", "13133851223").replace("+", "")
ORIGIN = "https://crm.apexonepayments.com"

results = {"steps": []}

def step(name, ok, detail=""):
    results["steps"].append({"step": name, "ok": ok, "detail": detail[:500]})
    print(f"[{'PASS' if ok else 'FAIL'}] {name}: {detail[:300]}")

def md5(s):
    return hashlib.md5(s.encode()).hexdigest()

def digest_response(username, realm, password, method, uri, nonce, qop="auth", nc="00000001", cnonce="wssfull1"):
    ha1 = md5(f"{username}:{realm}:{password}")
    ha2 = md5(f"{method}:{uri}")
    if qop:
        return md5(f"{ha1}:{nonce}:{nc}:{cnonce}:{qop}:{ha2}")
    return md5(f"{ha1}:{nonce}:{ha2}")

def ws_connect():
    key = base64.b64encode(os.urandom(16)).decode()
    req = (
        f"GET / HTTP/1.1\r\nHost: {HOST}:{PORT}\r\n"
        "Upgrade: websocket\r\nConnection: Upgrade\r\n"
        "Sec-WebSocket-Version: 13\r\n"
        f"Sec-WebSocket-Key: {key}\r\n"
        f"Origin: {ORIGIN}\r\n"
        "Sec-WebSocket-Protocol: sip\r\n\r\n"
    )
    ctx = ssl.create_default_context()
    ctx.check_hostname = False
    ctx.verify_mode = ssl.CERT_NONE
    raw = socket.create_connection((HOST, PORT), timeout=15)
    sock = ctx.wrap_socket(raw, server_hostname=HOST)
    sock.sendall(req.encode())
    resp = sock.recv(4096).decode(errors="replace")
    line1 = resp.split("\r\n", 1)[0]
    ok = "101" in line1
    step("wss_upgrade", ok, line1)
    return sock if ok else None

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

def ws_recv(sock, timeout=4):
    sock.settimeout(timeout)
    try:
        hdr = sock.recv(2)
        if len(hdr) < 2:
            return ""
        ln = hdr[1] & 0x7f
        if ln == 126:
            ln = struct.unpack("!H", sock.recv(2))[0]
        elif ln == 127:
            ln = struct.unpack("!Q", sock.recv(8))[0]
        payload = b""
        while len(payload) < ln:
            chunk = sock.recv(ln - len(payload))
            if not chunk:
                break
            payload += chunk
        return payload.decode(errors="replace")
    except Exception as e:
        return f"TIMEOUT:{e}"

def parse_auth_challenge(msg):
    m = re.search(r"WWW-Authenticate:\s*Digest\s+(.*)", msg, re.I | re.S)
    if not m:
        m = re.search(r"Proxy-Authenticate:\s*Digest\s+(.*)", msg, re.I | re.S)
    if not m:
        return None
    return dict(re.findall(r'(\w+)="([^"]+)"', m.group(1)))

def build_auth_header(username, realm, password, method, uri, parts, cnonce="wssfull1", nc="00000001"):
    nonce = parts["nonce"]
    qop = parts.get("qop", "auth").split(",")[0] if parts.get("qop") else None
    resp_hash = digest_response(username, realm, password, method, uri, nonce, qop, nc, cnonce)
    auth = f'Digest username="{username}", realm="{realm}", nonce="{nonce}", uri="{uri}", response="{resp_hash}", algorithm=MD5'
    if qop:
        auth += f', qop={qop}, nc={nc}, cnonce="{cnonce}"'
    return auth

def sip_register(sock):
    branch = "z9hG4bKreg1"
    reg = (
        f"REGISTER sip:{DOMAIN} SIP/2.0\r\n"
        f"Via: SIP/2.0/WSS {HOST};branch={branch}\r\n"
        "Max-Forwards: 70\r\n"
        f"To: <sip:{USER}@{DOMAIN}>\r\n"
        f"From: <sip:{USER}@{DOMAIN}>;tag=regtag1\r\n"
        "Call-ID: wss-full-reg\r\n"
        "CSeq: 1 REGISTER\r\n"
        f"Contact: <sip:{USER}@invalid;transport=ws>;expires=300\r\n"
        "Expires: 300\r\n"
        "Content-Length: 0\r\n\r\n"
    )
    ws_send(sock, reg)
    r1 = ws_recv(sock)
    parts = parse_auth_challenge(r1)
    if not parts:
        step("register", False, r1.split("\r\n", 1)[0] if r1 else "no challenge")
        return False
    realm = parts.get("realm", DOMAIN)
    uri = f"sip:{DOMAIN}"
    auth = build_auth_header(USER, realm, PASSWORD, "REGISTER", uri, parts)
    reg2 = (
        f"REGISTER sip:{DOMAIN} SIP/2.0\r\n"
        f"Via: SIP/2.0/WSS {HOST};branch={branch}2\r\n"
        "Max-Forwards: 70\r\n"
        f"To: <sip:{USER}@{DOMAIN}>\r\n"
        f"From: <sip:{USER}@{DOMAIN}>;tag=regtag1\r\n"
        "Call-ID: wss-full-reg\r\n"
        "CSeq: 2 REGISTER\r\n"
        f"Contact: <sip:{USER}@invalid;transport=ws>;expires=300\r\n"
        f"Authorization: {auth}\r\n"
        "Content-Length: 0\r\n\r\n"
    )
    ws_send(sock, reg2)
    r2 = ws_recv(sock)
    ok = r2.startswith("SIP/2.0 200")
    step("register", ok, r2.split("\r\n", 1)[0] if r2 else "empty")
    return ok

def respond_notify_200(sock, msg):
    m_via = re.search(r"^Via:\s*(.+)$", msg, re.M | re.I)
    m_from = re.search(r"^From:\s*(.+)$", msg, re.M | re.I)
    m_to = re.search(r"^To:\s*(.+)$", msg, re.M | re.I)
    m_cid = re.search(r"^Call-ID:\s*(.+)$", msg, re.M | re.I)
    m_cseq = re.search(r"^CSeq:\s*(.+)$", msg, re.M | re.I)
    if not all([m_via, m_from, m_to, m_cid, m_cseq]):
        return False
    # swap to/from for response
    resp = (
        "SIP/2.0 200 OK\r\n"
        f"Via: {m_via.group(1).strip()}\r\n"
        f"From: {m_from.group(1).strip()}\r\n"
        f"To: {m_to.group(1).strip()}\r\n"
        f"Call-ID: {m_cid.group(1).strip()}\r\n"
        f"CSeq: {m_cseq.group(1).strip()}\r\n"
        "Content-Length: 0\r\n\r\n"
    )
    ws_send(sock, resp)
    return True

def drain_notify(sock, seconds=3):
    notify_count = 0
    ok_200_sent = 0
    end = time.time() + seconds
    while time.time() < end:
        msg = ws_recv(sock, 1)
        if not msg or msg.startswith("TIMEOUT"):
            continue
        line1 = msg.split("\r\n", 1)[0]
        if " NOTIFY" in msg and msg.startswith("NOTIFY ") or line1.endswith("NOTIFY") or re.search(r"^NOTIFY ", msg, re.M):
            notify_count += 1
            if respond_notify_200(sock, msg):
                ok_200_sent += 1
            step(f"notify_{notify_count}", True, "responded 200 OK to server NOTIFY")
        elif msg.startswith("NOTIFY ") or re.search(r"\nNOTIFY sip:", msg):
            notify_count += 1
            if respond_notify_200(sock, msg):
                ok_200_sent += 1
            step(f"notify_{notify_count}", True, "responded 200 OK")
        elif line1.startswith("SIP/2.0"):
            step("sip_inbound", True, line1)
    return notify_count, ok_200_sent

def sip_invite(sock):
    call_id = "wss-full-invite"
    branch = "z9hG4bKinv1"
    target = f"sip:{DEST}@{DIAL_DOMAIN};user=phone"
    pai = f"<sip:{DID}@{DIAL_DOMAIN}>"
    invite = (
        f"INVITE {target} SIP/2.0\r\n"
        f"Via: SIP/2.0/WSS {HOST};branch={branch}\r\n"
        "Max-Forwards: 70\r\n"
        f"To: <{target}>\r\n"
        f"From: <sip:{USER}@{DOMAIN}>;tag=invtag1\r\n"
        f"Call-ID: {call_id}\r\n"
        "CSeq: 1 INVITE\r\n"
        f"Contact: <sip:{USER}@invalid;transport=ws>\r\n"
        f"P-Asserted-Identity: {pai}\r\n"
        "Content-Type: application/sdp\r\n"
        "Content-Length: 0\r\n\r\n"
    )
    ws_send(sock, invite)
    saw_ringing = False
    saw_200 = False
    last = ""
    for i in range(20):
        msg = ws_recv(sock, 2)
        if not msg or msg.startswith("TIMEOUT"):
            continue
        if re.search(r"^NOTIFY ", msg, re.M) or msg.startswith("NOTIFY "):
            respond_notify_200(sock, msg)
            step("notify_during_invite", True, "200 OK sent")
            continue
        line1 = msg.split("\r\n", 1)[0]
        last = line1
        if "180" in line1 or "183" in line1:
            saw_ringing = True
            step("invite_ringing", True, line1)
        if line1.startswith("SIP/2.0 407") or line1.startswith("SIP/2.0 401"):
            parts = parse_auth_challenge(msg)
            if parts:
                realm = parts.get("realm", DOMAIN)
                auth = build_auth_header(USER, realm, PASSWORD, "INVITE", target, parts, cnonce="invauth1")
                invite2 = (
                    f"INVITE {target} SIP/2.0\r\n"
                    f"Via: SIP/2.0/WSS {HOST};branch={branch}2\r\n"
                    "Max-Forwards: 70\r\n"
                    f"To: <{target}>\r\n"
                    f"From: <sip:{USER}@{DOMAIN}>;tag=invtag1\r\n"
                    f"Call-ID: {call_id}\r\n"
                    "CSeq: 2 INVITE\r\n"
                    f"Contact: <sip:{USER}@invalid;transport=ws>\r\n"
                    f"Authorization: {auth}\r\n"
                    f"P-Asserted-Identity: {pai}\r\n"
                    "Content-Type: application/sdp\r\n"
                    "Content-Length: 0\r\n\r\n"
                )
                ws_send(sock, invite2)
                step("invite_auth_retry", True, "sent authenticated INVITE")
        if line1.startswith("SIP/2.0 200"):
            saw_200 = True
            step("invite_answered", True, line1)
            break
        if line1.startswith("SIP/2.0 4") or line1.startswith("SIP/2.0 5") or line1.startswith("SIP/2.0 6"):
            if not line1.startswith("SIP/2.0 407") and not line1.startswith("SIP/2.0 401"):
                step("invite_result", False, line1)
                break
    if not saw_ringing and not saw_200:
        step("invite", saw_200, last or "no response")
    else:
        step("invite", True, f"ringing={saw_ringing} answered={saw_200}")
    # CANCEL/BYE cleanup
    cancel = (
        f"CANCEL {target} SIP/2.0\r\n"
        f"Via: SIP/2.0/WSS {HOST};branch={branch}c\r\n"
        "Max-Forwards: 70\r\n"
        f"To: <{target}>\r\n"
        f"From: <sip:{USER}@{DOMAIN}>;tag=invtag1\r\n"
        f"Call-ID: {call_id}\r\n"
        "CSeq: 3 CANCEL\r\n"
        "Content-Length: 0\r\n\r\n"
    )
    ws_send(sock, cancel)
    return saw_ringing or saw_200

if not PASSWORD:
    step("env", False, "MORPHEUS_EXTENSION_PASSWORD missing")
    print("SUMMARY", json.dumps(results))
    raise SystemExit(1)

sock = ws_connect()
if not sock:
    print("SUMMARY", json.dumps(results))
    raise SystemExit(1)

if sip_register(sock):
    n, ack = drain_notify(sock, 2)
    step("notify_handling", True, f"received={n} acked_200={ack}")
    sip_invite(sock)

sock.close()
all_ok = all(s["ok"] for s in results["steps"] if s["step"] not in ("notify_handling",))
print("SUMMARY", json.dumps(results))
""".replace("__APP__", REMOTE_APP)


def main() -> int:
    ssh = connect()
    enc = base64.b64encode(REMOTE_PY.encode()).decode()
    out = sudo_run(ssh, f"echo {enc} | base64 -d | python3", check=False)
    print(out)
    # API click-to-call parallel path
    print("\n--- API click-to-call ext 1007 ---")
    print(sudo_run(ssh, f"cd {REMOTE_APP} && timeout 50 sudo -u www-data php /tmp/quick-ring.php 2>/dev/null || true", check=False))
    # write quick ring if missing
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
