#!/usr/bin/env python3
"""Verify Morpheus WSS is configured and reachable from production server."""
from __future__ import annotations

import base64
import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, set_env_vars, sudo_run

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
    'sip_wss_url_env' => config('integrations.morpheus.sip_wss_url'),
    'resolved_wss_for_webphone' => $wss,
    'crm_proxy_note' => 'crm.apexonepayments.com/morpheus-ws is NOT used for calling anymore',
    'use_instead' => $wss,
], JSON_PRETTY_PRINT);
"""

def main() -> int:
    ssh = connect()
    set_env_vars(ssh, {
        "MORPHEUS_SIP_WSS_URL": "wss://apexone.morpheus.cx:7443/",
        "MORPHEUS_HOST": "apexone.morpheus.cx",
    })
    sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php artisan config:cache")
    enc = base64.b64encode(PHP.encode()).decode()
    php_out = sudo_run(ssh, f"cd {REMOTE_APP} && echo {enc} | base64 -d | sudo -u www-data php")
    # WSS handshake probe from server (not browser)
    wss_probe = sudo_run(
        ssh,
        "python3 - <<'PY'\n"
        "import ssl, socket\n"
        "host='apexone.morpheus.cx'\n"
        "port=7443\n"
        "ctx=ssl.create_default_context()\n"
        "s=ctx.wrap_socket(socket.socket(), server_hostname=host)\n"
        "s.settimeout(8)\n"
        "s.connect((host, port))\n"
        "req=(\n"
        "    'GET / HTTP/1.1\\r\\n'\n"
        "    f'Host: {host}:{port}\\r\\n'\n"
        "    'Upgrade: websocket\\r\\n'\n"
        "    'Connection: Upgrade\\r\\n'\n"
        "    'Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\\r\\n'\n"
        "    'Sec-WebSocket-Version: 13\\r\\n'\n"
        "    'Sec-WebSocket-Protocol: sip\\r\\n\\r\\n'\n"
        ")\n"
        "s.send(req.encode())\n"
        "resp=s.recv(512).decode(errors='replace')\n"
        "s.close()\n"
        "print(resp.split('\\r\\n')[0])\n"
        "PY",
        check=False,
    )
    ssh.close()
    data = json.loads(php_out)
    data["wss_handshake_from_server"] = wss_probe.strip()
    print(json.dumps(data, indent=2))
    ok = "apexone.morpheus.cx:7443" in (data.get("resolved_wss_for_webphone") or "")
    ok = ok and "101" in (data.get("wss_handshake_from_server") or "")
    print("OK - webphone uses direct Morpheus WSS" if ok else "CHECK")
    return 0 if ok else 1

if __name__ == "__main__":
    raise SystemExit(main())
