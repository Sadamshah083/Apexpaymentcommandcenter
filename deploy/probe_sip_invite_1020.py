#!/usr/bin/env python3
"""Probe PSTN INVITE over WSS for ext 1020 — capture final SIP response."""
from __future__ import annotations
import base64
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import REMOTE_APP, connect, sudo_run

REMOTE_PY = r'''
import hashlib, ssl, socket, base64, struct, os, re, pathlib, json

env = {}
for line in pathlib.Path("__APP__/.env").read_text().splitlines():
    if "=" in line and not line.strip().startswith("#"):
        k, v = line.split("=", 1)
        env[k.strip()] = v.strip()

PASSWORD = env.get("MORPHEUS_EXTENSION_PASSWORD", "")
USER = "1020"
DOMAIN = env.get("MORPHEUS_WEBRTC_SIP_DOMAIN", "apexone.pbx.local")
HOST = env.get("MORPHEUS_HOST", "apexone.morpheus.cx")
PREFIX = env.get("MORPHEUS_OUTBOUND_PREFIX", "")
DEST = "12722001232"
DID = env.get("COMMUNICATIONS_DEFAULT_OUTBOUND_DID", "13133851223").replace("+", "")
PORT = 7443
CAMPAIGN = env.get("MORPHEUS_DEFAULT_CAMPAIGN_ID", "")

def md5(s):
    return hashlib.md5(s.encode()).hexdigest()

def digest_response(username, realm, password, method, uri, nonce, qop="auth", nc="00000001", cnonce="inv1"):
    ha1 = md5(f"{username}:{realm}:{password}")
    ha2 = md5(f"{method}:{uri}")
    if qop:
        return md5(f"{ha1}:{nonce}:{nc}:{cnonce}:{qop}:{ha2}")
    return md5(f"{ha1}:{nonce}:{ha2}")

def build_auth(user, realm, password, method, uri, parts, cnonce="inv1", nc="00000001"):
    nonce = parts["nonce"]
    qop = parts.get("qop", "auth").split(",")[0] if parts.get("qop") else None
    resp = digest_response(user, realm, password, method, uri, nonce, qop, nc, cnonce)
    hdr = f'Digest username="{user}", realm="{realm}", nonce="{nonce}", uri="{uri}", response="{resp}", algorithm=MD5'
    if qop:
        hdr += f', qop={qop}, nc={nc}, cnonce="{cnonce}"'
    return hdr

def parse_auth(resp):
    m = re.search(r"(?:Proxy-Authenticate|WWW-Authenticate):\s*Digest\s+(.*)", resp, re.I)
    if not m:
        return None
    parts = {}
    for key, val in re.findall(r'(\w+)="([^"]+)"', m.group(1)):
        parts[key] = val
    return parts

def ws_connect():
    key = base64.b64encode(os.urandom(16)).decode()
    req = (
        f"GET / HTTP/1.1\r\nHost: {HOST}:{PORT}\r\n"
        "Upgrade: websocket\r\nConnection: Upgrade\r\n"
        "Sec-WebSocket-Version: 13\r\n"
        f"Sec-WebSocket-Key: {key}\r\n"
        "Origin: https://crm.apexonepayments.com\r\n"
        "Sec-WebSocket-Protocol: sip\r\n\r\n"
    )
    ctx = ssl.create_default_context()
    ctx.check_hostname = False
    ctx.verify_mode = ssl.CERT_NONE
    raw = socket.create_connection((HOST, PORT), timeout=15)
    sock = ctx.wrap_socket(raw, server_hostname=HOST)
    sock.sendall(req.encode())
    resp = sock.recv(4096).decode(errors="replace")
    if "101" not in resp.split("\r\n", 1)[0]:
        print(json.dumps({"error": "ws_failed", "detail": resp[:200]}))
        return None
    return sock

def ws_send(sock, text):
    data = text.encode()
    mask = os.urandom(4)
    frame = bytearray([0x81])
    ln = len(data)
    frame.append(0x80 | (126 if ln >= 126 else ln))
    if ln >= 126:
        frame.extend(struct.pack("!H", ln))
    frame.extend(mask)
    frame.extend(bytes(b ^ mask[i % 4] for i, b in enumerate(data)))
    sock.sendall(frame)

def ws_recv(sock, timeout=3):
    sock.settimeout(timeout)
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

def sip_register(sock):
    branch = "z9hG4bKreg"
    reg = (
        f"REGISTER sip:{DOMAIN} SIP/2.0\r\n"
        f"Via: SIP/2.0/WSS {HOST};branch={branch}\r\n"
        "Max-Forwards: 70\r\n"
        f"To: <sip:{USER}@{DOMAIN}>\r\n"
        f"From: <sip:{USER}@{DOMAIN}>;tag=reg1\r\n"
        "Call-ID: probe-reg-1020\r\n"
        "CSeq: 1 REGISTER\r\n"
        f"Contact: <sip:{USER}@invalid;transport=ws>;expires=120\r\n"
        "Expires: 120\r\n"
        "Content-Length: 0\r\n\r\n"
    )
    ws_send(sock, reg)
    r1 = ws_recv(sock)
    auth = parse_auth(r1)
    if not auth:
        return r1.split("\r\n", 1)[0]
    realm = auth.get("realm", DOMAIN)
    uri = f"sip:{DOMAIN}"
    auth_hdr = build_auth(USER, realm, PASSWORD, "REGISTER", uri, auth, cnonce="reg1")
    reg2 = (
        f"REGISTER sip:{DOMAIN} SIP/2.0\r\n"
        f"Via: SIP/2.0/WSS {HOST};branch={branch}2\r\n"
        "Max-Forwards: 70\r\n"
        f"To: <sip:{USER}@{DOMAIN}>\r\n"
        f"From: <sip:{USER}@{DOMAIN}>;tag=reg1\r\n"
        "Call-ID: probe-reg-1020\r\n"
        "CSeq: 2 REGISTER\r\n"
        f"Contact: <sip:{USER}@invalid;transport=ws>;expires=120\r\n"
        f"Authorization: {auth_hdr}\r\n"
        "Expires: 120\r\n"
        "Content-Length: 0\r\n\r\n"
    )
    ws_send(sock, reg2)
    r2 = ws_recv(sock)
    return r2.split("\r\n", 1)[0]

def sip_invite(sock, dial_user):
    dial_domain = HOST
    target = f"sip:{dial_user}@{dial_domain};user=phone"
    call_id = "probe-inv-1020"
    branch = "z9hG4bKinv"
    pai = f"<sip:{DID}@{dial_domain}>"
    extra = f"X-Campaign-ID: {CAMPAIGN}\r\n" if CAMPAIGN else ""
    cseq = 1
    responses = []

    def send_invite(proxy_auth=None, auth=None):
        nonlocal cseq
        invite = (
            f"INVITE {target} SIP/2.0\r\n"
            f"Via: SIP/2.0/WSS {HOST};branch={branch}{cseq}\r\n"
            "Max-Forwards: 70\r\n"
            f"To: <{target}>\r\n"
            f"From: <sip:{USER}@{DOMAIN}>;tag=inv1\r\n"
            f"Call-ID: {call_id}\r\n"
            f"CSeq: {cseq} INVITE\r\n"
            f"Contact: <sip:{USER}@invalid;transport=ws>\r\n"
            f"P-Preferred-Identity: <sip:{USER}@{DOMAIN}>\r\n"
            f"P-Asserted-Identity: {pai}\r\n"
            f"Remote-Party-ID: {pai};party=calling;privacy=off;screen=no\r\n"
            f"{extra}"
        )
        if proxy_auth:
            invite += f"Proxy-Authorization: {proxy_auth}\r\n"
        if auth:
            invite += f"Authorization: {auth}\r\n"
        invite += (
            "Content-Type: application/sdp\r\n"
            "Content-Length: 0\r\n\r\n"
        )
        ws_send(sock, invite)
        cseq += 1

    send_invite()
    saw_ringing = False
    final = ""
    for _ in range(25):
        msg = ws_recv(sock, 2)
        if not msg:
            continue
        if msg.startswith("NOTIFY ") or "\nNOTIFY " in msg:
            via_m = re.search(r"^Via: (.+)$", msg, re.M)
            cseq_m = re.search(r"^CSeq: (\d+) NOTIFY$", msg, re.M)
            if via_m and cseq_m:
                ok = (
                    "SIP/2.0 200 OK\r\n"
                    f"Via: {via_m.group(1)}\r\n"
                    f"CSeq: {cseq_m.group(1)} OK\r\n"
                    "Content-Length: 0\r\n\r\n"
                )
                ws_send(sock, ok)
            continue
        line1 = msg.split("\r\n", 1)[0]
        responses.append(line1)
        if "180" in line1 or "183" in line1:
            saw_ringing = True
        if line1.startswith("SIP/2.0 407"):
            auth = parse_auth(msg)
            if auth:
                realm = auth.get("realm", DOMAIN)
                proxy_hdr = build_auth(USER, realm, PASSWORD, "INVITE", target, auth, cnonce=f"inv{cseq}")
                send_invite(proxy_auth=proxy_hdr)
            continue
        if line1.startswith("SIP/2.0 401"):
            auth = parse_auth(msg)
            if auth:
                realm = auth.get("realm", DOMAIN)
                auth_hdr = build_auth(USER, realm, PASSWORD, "INVITE", target, auth, cnonce=f"inv{cseq}")
                send_invite(auth=auth_hdr)
            continue
        if line1.startswith("SIP/2.0 200"):
            final = line1
            break
        if re.match(r"SIP/2.0 [456]", line1) and not line1.startswith("SIP/2.0 407") and not line1.startswith("SIP/2.0 401"):
            final = line1
            break
        if line1.startswith("BYE "):
            final = "BYE received"
            break
    return {
        "dial_user": dial_user,
        "target": target,
        "prefix_env": PREFIX,
        "register": sip_register(sock) if False else None,
        "responses": responses[-8:],
        "ringing": saw_ringing,
        "final": final or (responses[-1] if responses else "no response"),
    }

if not PASSWORD:
    print(json.dumps({"error": "no password"}))
    raise SystemExit(1)

sock = ws_connect()
if not sock:
    raise SystemExit(1)
reg_status = sip_register(sock)
results = {"register": reg_status, "attempts": []}
for dial in [DEST, (PREFIX.rstrip("#") + "#" + DEST if PREFIX else DEST)]:
    if dial == DEST or PREFIX:
        results["attempts"].append(sip_invite(sock, dial))
sock.close()
print(json.dumps(results, indent=2))
'''.replace("__APP__", REMOTE_APP)


def main() -> int:
    ssh = connect()
    enc = base64.b64encode(REMOTE_PY.encode()).decode()
    print(sudo_run(ssh, f"echo {enc} | base64 -d | python3", check=False))
    ssh.close()
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
