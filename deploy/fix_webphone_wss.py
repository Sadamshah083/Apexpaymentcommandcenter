#!/usr/bin/env python3
"""Point production webphone at direct Morpheus WSS and verify SIP display name."""
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
$svc = app(App\Services\Communications\CommunicationsWebphoneService::class);
$ref = new ReflectionClass($svc);
$wss = $ref->getMethod('resolveWssUrl');
$wss->setAccessible(true);
$host = app(App\Services\Communications\ZoomClickToCallService::class)->publicWssHost();
$display = $ref->getMethod('resolveSipDisplayName');
$display->setAccessible(true);
$agents = app(App\Services\Communications\CommunicationsAgentService::class);
$dial = $agents->extensionDialOptions('1020');
echo json_encode([
    'morpheus_host' => config('integrations.morpheus.host'),
    'sip_wss_url' => config('integrations.morpheus.sip_wss_url'),
    'resolved_wss' => $wss->invoke($svc, $host),
    'display_name_1020' => $display->invoke($svc, $dial),
    'outbound_caller_id_1020' => $dial['caller_id_number'] ?? null,
    'dial_method' => config('integrations.morpheus.dial_method'),
], JSON_PRETTY_PRINT);
"""

def main() -> int:
    ssh = connect()
    set_env_vars(ssh, {
        "MORPHEUS_SIP_WSS_URL": "wss://apexone.morpheus.cx:7443/",
        "MORPHEUS_HOST": "apexone.morpheus.cx",
        "MORPHEUS_DIAL_METHOD": "api",
        "MORPHEUS_WEBPHONE_AUTO_ANSWER": "true",
    })
    sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php artisan config:cache")
    enc = base64.b64encode(PHP.encode()).decode()
    out = sudo_run(ssh, f"cd {REMOTE_APP} && echo {enc} | base64 -d | sudo -u www-data php")
    ssh.close()
    data = json.loads(out)
    print(json.dumps(data, indent=2))
    ok = (
        "apexone.morpheus.cx:7443" in (data.get("resolved_wss") or "")
        and (data.get("display_name_1020") or "").isdigit()
    )
    print("OK" if ok else "CHECK FAILED")
    return 0 if ok else 1

if __name__ == "__main__":
    raise SystemExit(main())
