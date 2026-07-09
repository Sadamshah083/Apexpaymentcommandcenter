#!/usr/bin/env python3
"""
Continuous outbound ring test — tries every Morpheus originate path in rotation.
Stop with Ctrl+C. User confirms when cell rings.
"""
from __future__ import annotations

import sys
import time
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run  # noqa: E402

DEST = "+12722001232"
DEST_DIGITS = "12722001232"
DID = "13133851223"
EXTENSIONS = ["1007", "1004", "1008", "1001", "1020"]
POLL_SEC = 25
PAUSE_BETWEEN = 3

RING_WORKER = r"""<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Communications\CommunicationsAgentService;
use App\Services\Integrations\ZoomApiService;
use Illuminate\Support\Facades\Http;

$label = $argv[1] ?? 'unknown';
$ext = preg_replace('/\D/', '', $argv[2] ?? '1020') ?: '1020';
$destDigits = preg_replace('/\D/', '', $argv[3] ?? '12722001232');
$pollSec = max(8, (int)($argv[4] ?? 20));
$mode = $argv[5] ?? 'c2c';

$api = app(ZoomApiService::class);
$agents = app(CommunicationsAgentService::class);
$opts = $agents->extensionDialOptions($ext);
$key = config('integrations.morpheus.api_key');
$host = config('integrations.morpheus.host');
$base = "https://{$host}/api/v1/call-control";
$campaign = $opts['campaign_id'] ?? config('integrations.morpheus.default_campaign_id');
$cid = $opts['caller_id_number'] ?? config('integrations.communications.default_outbound_did', '13133851223');
$extras = array_filter([
    'campaign_id' => $campaign,
    'caller_id_number' => $cid,
    'timeout_sec' => 90,
], fn ($v) => filled($v));

echo "=== {$label} ext={$ext} dest=+{$destDigits} mode={$mode} ===\n";
echo "ts=".gmdate('c')." online=".json_encode($agents->extensionEndpointOnline($ext))."\n";

if (in_array($mode, ['c2c', 'orig', 'app', 'app_cf'], true)) {
    $api->clearExtensionForOutboundDial($ext, kickSip: false);
    usleep(500000);
}

$uuid = null;
$http = 0;

if ($mode === 'c2c') {
    $r = Http::timeout(25)->acceptJson()->withHeaders(['X-API-Key' => $key])
        ->post("{$base}/click-to-call", array_merge(['extension' => $ext, 'destination' => $destDigits], $extras));
    $http = $r->status();
    echo "POST c2c HTTP {$http} ".$r->body()."\n";
    $uuid = $r->json('call_uuid');
} elseif ($mode === 'orig') {
    $r = Http::timeout(25)->acceptJson()->withHeaders(['X-API-Key' => $key])
        ->post("{$base}/calls/originate", array_merge(['from' => $ext, 'to' => $destDigits], $extras));
    $http = $r->status();
    echo "POST orig HTTP {$http} ".$r->body()."\n";
    $uuid = $r->json('call_uuid');
} elseif ($mode === 'app') {
    $r = $api->originateCall($ext, '+'.$destDigits, $opts);
    echo "APP originate ".json_encode($r)."\n";
    $uuid = $r['call_uuid'] ?? null;
} elseif ($mode === 'cell_first') {
    $r = Http::timeout(25)->acceptJson()->withHeaders(['X-API-Key' => $key])
        ->post("{$base}/calls/originate", array_merge([
            'from' => $destDigits,
            'to' => $ext,
            'timeout_sec' => 90,
        ], $extras));
    $http = $r->status();
    echo "POST cell->ext HTTP {$http} ".$r->body()."\n";
    $uuid = $r->json('call_uuid');
} elseif ($mode === 'did_pstn') {
    $r = Http::timeout(25)->acceptJson()->withHeaders(['X-API-Key' => $key])
        ->post("{$base}/calls/originate", [
            'from' => preg_replace('/\D/', '', (string)$cid),
            'to' => $destDigits,
            'timeout_sec' => 90,
            'campaign_id' => $campaign,
            'caller_id_number' => preg_replace('/\D/', '', (string)$cid),
        ]);
    $http = $r->status();
    echo "POST did->pstn HTTP {$http} ".$r->body()."\n";
    $uuid = $r->json('call_uuid');
} elseif ($mode === 'app_cf') {
    $r = $api->originateCall($ext, '+'.$destDigits, array_merge($opts, ['customer_first' => true]));
    echo "APP customer_first ".json_encode($r)."\n";
    $uuid = $r['call_uuid'] ?? null;
} else {
    echo "UNKNOWN mode\n";
    exit(1);
}

if (!$uuid) {
    echo "VERDICT=NO_UUID\n";
    exit(0);
}

$maxBill = 0;
$sawLive = false;
$lastCause = '';
$lastDest = '';
for ($i = 0; $i < $pollSec; $i++) {
    sleep(1);
    $snap = $api->getCall($uuid) ?? [];
    $live = (bool)($snap['live'] ?? false);
    $bill = (int)($snap['billsec'] ?? $snap['duration_sec'] ?? 0);
    $cause = strtoupper((string)($snap['hangup_cause'] ?? ''));
    $state = strtoupper((string)($snap['state'] ?? $snap['status'] ?? ''));
    if ($live) $sawLive = true;
    $maxBill = max($maxBill, $bill);
    if ($cause !== '') $lastCause = $cause;
    $cdr = $api->listCdr(['limit' => 5, 'call_uuid' => $uuid]);
    foreach ($cdr['cdr'] ?? $cdr['cdrs'] ?? [] as $row) {
        $lastDest = (string)($row['destination_number'] ?? $row['destination'] ?? $lastDest);
        $maxBill = max($maxBill, (int)($row['billsec'] ?? 0));
        $c = strtoupper((string)($row['hangup_cause'] ?? ''));
        if ($c !== '') $lastCause = $c;
    }
    echo sprintf("  t=%02ds live=%s state=%s billsec=%d cause=%s dest=%s\n",
        $i + 1, $live ? 'Y' : 'N', $state ?: '-', $bill, $cause ?: '-', $lastDest ?: '-');
    if (!$live && $cause !== '' && $bill < 1 && $i >= 3) break;
}

$api->hangup($uuid);
$verdict = ($sawLive || $maxBill >= 1) ? 'ACTIVE_OR_BILLED' : (
    $lastCause === 'USER_BUSY' ? 'USER_BUSY' : (
        $lastCause === 'NO_USER_RESPONSE' ? 'NO_ANSWER' : 'ENDED'
    )
);
echo "VERDICT={$verdict} max_billsec={$maxBill} cause={$lastCause} cdr_dest={$lastDest}\n";
"""


WSS_INVITE = r"""
import base64, hashlib, os, re, socket, ssl, struct, time, pathlib

env = {}
for line in pathlib.Path("__APP__/.env").read_text().splitlines():
    if "=" in line and not line.strip().startswith("#"):
        k, v = line.split("=", 1)
        env[k.strip()] = v.strip()

PASSWORD = env.get("MORPHEUS_EXTENSION_PASSWORD", "")
USER = "1020"
DOMAIN = env.get("MORPHEUS_WEBRTC_SIP_DOMAIN", "apexone.pbx.local")
HOST = env.get("MORPHEUS_HOST", "apexone.morpheus.cx")
PORT = 7443
DEST = "12722001232"
DID = env.get("COMMUNICATIONS_DEFAULT_OUTBOUND_DID", "13133851223").replace("+", "")

def md5(s):
    return hashlib.md5(s.encode()).hexdigest()

def digest_response(username, realm, password, method, uri, nonce, qop="auth", nc="00000001", cnonce="sipprobe1"):
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
    raw = socket.create_connection((HOST, PORT), timeout=15)
    sock = ctx.wrap_socket(raw, server_hostname=HOST)
    sock.sendall(req.encode())
    resp = sock.recv(4096).decode(errors="replace")
    if "101" not in resp.split("\r\n", 1)[0]:
        print("WS_FAIL", resp[:180])
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

def ws_recv(sock, timeout=5):
    sock.settimeout(timeout)
    try:
        hdr = sock.recv(2)
        if len(hdr) < 2:
            return ""
        ln = hdr[1] & 0x7f
        if ln == 126:
            ln = struct.unpack("!H", sock.recv(2))[0]
        payload = sock.recv(ln)
        return payload.decode(errors="replace")
    except Exception as e:
        return f"TIMEOUT:{e}"

def sip_auth_register(sock):
    branch = "z9hG4bKreg"
    reg = (
        f"REGISTER sip:{DOMAIN} SIP/2.0\r\n"
        f"Via: SIP/2.0/WSS {HOST};branch={branch}\r\n"
        "Max-Forwards: 70\r\n"
        f"To: <sip:{USER}@{DOMAIN}>\r\n"
        f"From: <sip:{USER}@{DOMAIN}>;tag=reg1\r\n"
        "Call-ID: wss-ring-reg\r\n"
        "CSeq: 1 REGISTER\r\n"
        f"Contact: <sip:{USER}@invalid;transport=ws>;expires=120\r\n"
        "Expires: 120\r\n"
        "Content-Length: 0\r\n\r\n"
    )
    ws_send(sock, reg)
    r1 = ws_recv(sock)
    m = re.search(r"WWW-Authenticate:\s*Digest\s+(.*)", r1, re.I)
    if not m:
        print("REG_NO_CHALLENGE", r1[:200])
        return False
    parts = dict(re.findall(r'(\w+)="([^"]+)"', m.group(1)))
    realm = parts.get("realm", DOMAIN)
    nonce = parts["nonce"]
    uri = f"sip:{DOMAIN}"
    qop = parts.get("qop", "auth").split(",")[0] if parts.get("qop") else None
    cnonce = "ringloop1"
    resp_hash = digest_response(USER, realm, PASSWORD, "REGISTER", uri, nonce, qop, "00000001", cnonce)
    auth = f'Digest username="{USER}", realm="{realm}", nonce="{nonce}", uri="{uri}", response="{resp_hash}", algorithm=MD5'
    if qop:
        auth += f', qop={qop}, nc=00000001, cnonce="{cnonce}"'
    reg2 = (
        f"REGISTER sip:{DOMAIN} SIP/2.0\r\n"
        f"Via: SIP/2.0/WSS {HOST};branch={branch}2\r\n"
        "Max-Forwards: 70\r\n"
        f"To: <sip:{USER}@{DOMAIN}>\r\n"
        f"From: <sip:{USER}@{DOMAIN}>;tag=reg1\r\n"
        "Call-ID: wss-ring-reg\r\n"
        "CSeq: 2 REGISTER\r\n"
        f"Contact: <sip:{USER}@invalid;transport=ws>;expires=120\r\n"
        f"Authorization: {auth}\r\n"
        "Content-Length: 0\r\n\r\n"
    )
    ws_send(sock, reg2)
    r2 = ws_recv(sock)
    ok = r2.startswith("SIP/2.0 200")
    print("REGISTER", r2.split("\r\n", 1)[0] if r2 else "empty")
    return ok

print("=== WSS_SIP_INVITE ext", USER, "->", DEST, "===")
if not PASSWORD:
    print("NO_PASSWORD")
    raise SystemExit(1)
sock = ws_connect()
if not sock:
    raise SystemExit(1)
if not sip_auth_register(sock):
    sock.close()
    raise SystemExit(1)

call_id = "wss-ring-invite"
branch = "z9hG4bKinv"
target = f"sip:{DEST}@{DOMAIN}"
pai = f"<sip:{DID}@{DOMAIN}>"
invite = (
    f"INVITE {target} SIP/2.0\r\n"
    f"Via: SIP/2.0/WSS {HOST};branch={branch}\r\n"
    "Max-Forwards: 70\r\n"
    f"To: <{target}>\r\n"
    f"From: <sip:{USER}@{DOMAIN}>;tag=inv1\r\n"
    f"Call-ID: {call_id}\r\n"
    "CSeq: 1 INVITE\r\n"
    f"Contact: <sip:{USER}@invalid;transport=ws>\r\n"
    f"P-Asserted-Identity: {pai}\r\n"
    f"Remote-Party-ID: {pai};party=calling;privacy=off;screen=no\r\n"
    "Content-Type: application/sdp\r\n"
    "Content-Length: 0\r\n\r\n"
)
ws_send(sock, invite)
for i in range(25):
    r = ws_recv(sock, 2)
    if not r or r.startswith("TIMEOUT"):
        continue
    line = r.split("\r\n", 1)[0]
    print(f"  t={i+1}s {line}")
    if line.startswith("SIP/2.0 18") or "180" in line[:20]:
        print("RINGING_DETECTED")
    if line.startswith("SIP/2.0 200"):
        print("ANSWERED_200")
        break
    if line.startswith("SIP/2.0 4") or line.startswith("SIP/2.0 5") or line.startswith("SIP/2.0 6"):
        break
bye = (
    f"BYE {target} SIP/2.0\r\n"
    f"Via: SIP/2.0/WSS {HOST};branch={branch}b\r\n"
    "Max-Forwards: 70\r\n"
    f"To: <{target}>\r\n"
    f"From: <sip:{USER}@{DOMAIN}>;tag=inv1\r\n"
    f"Call-ID: {call_id}\r\n"
    "CSeq: 2 BYE\r\n"
    "Content-Length: 0\r\n\r\n"
)
ws_send(sock, bye)
sock.close()
print("WSS_INVITE_DONE")
""".replace("__APP__", REMOTE_APP)


def ts() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S UTC")


def upload_worker(ssh) -> str:
    remote = f"{REMOTE_APP}/scripts/ring_once_worker.php"
    tmp = "/tmp/ring_once_worker.php"
    sftp = ssh.open_sftp()
    with sftp.file(tmp, "w") as f:
        f.write(RING_WORKER)
    sftp.close()
    sudo_run(ssh, f"cp {tmp} {remote} && chown www-data:www-data {remote}")
    return remote


def run_attempt(ssh, remote: str, label: str, ext: str, mode: str) -> str:
    cmd = (
        f"cd {REMOTE_APP} && sudo -u www-data php {remote} "
        f"{label!s} {ext} {DEST_DIGITS} {POLL_SEC} {mode}"
    )
    return sudo_run(ssh, cmd, check=False)


def run_wss_invite(ssh) -> str:
    import base64

    enc = base64.b64encode(WSS_INVITE.encode()).decode()
    return sudo_run(ssh, f"echo {enc} | base64 -d | python3", check=False)


def scenarios() -> list[tuple[str, str, str]]:
    out: list[tuple[str, str, str]] = []
    # C2C is the only path confirmed to hit PSTN in CDR (originate misroutes to ext).
    for ext in EXTENSIONS:
        out.append((f"C2C-{ext}", ext, "c2c"))
    for ext in ["1007", "1004", "1008"]:
        out.append((f"ORIG-{ext}", ext, "orig"))
    return out


def main() -> int:
    print(f"[{ts()}] Continuous ring loop starting — destination {DEST}")
    print(f"Extensions: {', '.join(EXTENSIONS)} | poll={POLL_SEC}s | pause={PAUSE_BETWEEN}s")
    sys.stdout.flush()

    ssh = connect()
    remote = upload_worker(ssh)
    scenarios_list = scenarios()
    round_num = 0

    try:
        while True:
            round_num += 1
            print(f"\n{'='*72}\n[{ts()}] ROUND {round_num} — {len(scenarios_list)} API attempts + WSS INVITE\n{'='*72}")
            sys.stdout.flush()

            for label, ext, mode in scenarios_list:
                print(f"\n>>> [{ts()}] ATTEMPT: {label} ({mode}) — YOUR CELL MAY RING NOW <<<")
                sys.stdout.flush()
                out = run_attempt(ssh, remote, label, ext, mode)
                print(out)
                sys.stdout.flush()
                time.sleep(PAUSE_BETWEEN)

            print(f"\n>>> [{ts()}] ATTEMPT: WSS_SIP_INVITE (browser-like direct dial) <<<")
            sys.stdout.flush()
            print(run_wss_invite(ssh))
            sys.stdout.flush()
            time.sleep(PAUSE_BETWEEN)

    except KeyboardInterrupt:
        print(f"\n[{ts()}] Stopped by user after {round_num} round(s).")
    finally:
        ssh.close()

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
