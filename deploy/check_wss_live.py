#!/usr/bin/env python3
"""Read-only: verify Morpheus WSS URL config and handshake from production."""
from __future__ import annotations

import base64
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
$host = app(App\Services\Communications\ZoomClickToCallService::class)->publicWssHost();
$svc = app(App\Services\Communications\CommunicationsWebphoneService::class);
$ref = new ReflectionClass($svc);
$m = $ref->getMethod('resolveWssUrl');
$m->setAccessible(true);
$wss = $m->invoke($svc, $host);
echo json_encode([
    'morpheus_host' => config('integrations.morpheus.host'),
    'sip_wss_url' => config('integrations.morpheus.sip_wss_url'),
    'resolved_wss' => $wss,
], JSON_PRETTY_PRINT);
"""

WSS_PROBE = r"""python3 -c "import ssl,socket;h='apexone.morpheus.cx';p=7443;c=ssl.create_default_context();s=c.wrap_socket(socket.socket(),server_hostname=h);s.settimeout(10);s.connect((h,p));r='GET / HTTP/1.1\r\nHost: %s:%s\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\nSec-WebSocket-Protocol: sip\r\n\r\n'%(h,p);s.send(r.encode());print(s.recv(256).decode(errors='replace').split('\r\n')[0]);s.close()" """


def main() -> int:
    ssh = connect()
    enc = base64.b64encode(PHP.encode()).decode()
    cfg = json.loads(sudo_run(ssh, f"cd {REMOTE_APP} && echo {enc} | base64 -d | sudo -u www-data php"))
    probe = sudo_run(ssh, WSS_PROBE, check=False).strip()
    port = sudo_run(
        ssh,
        "nc -z -w 5 apexone.morpheus.cx 7443 && echo open || echo closed",
        check=False,
    ).strip()
    ssh.close()

    working = "101" in probe and port == "open"
    result = {
        "working": working,
        "url": cfg.get("resolved_wss") or cfg.get("sip_wss_url"),
        "morpheus_host": cfg.get("morpheus_host"),
        "port_7443": port,
        "handshake_status": probe,
        "note": "HTTP 400 on https://host:7443/ in a browser is normal. WebSocket must use wss:// with SIP protocol.",
    }
    print(json.dumps(result, indent=2))
    print("WORKING" if working else "FAILED")
    return 0 if working else 1


if __name__ == "__main__":
    raise SystemExit(main())
