#!/usr/bin/env python3
"""Clear carrier tech prefix on production and verify recent CDR destinations."""
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
echo json_encode([
    'outbound_prefix' => config('integrations.morpheus.outbound_prefix'),
    'dial_method' => config('integrations.morpheus.dial_method'),
    'click_to_call_destination_sample' => app(App\Services\Integrations\ZoomApiService::class)
        ->normalizeOriginateDestination('+12722001232'),
], JSON_PRETTY_PRINT);
"""

def main() -> int:
    ssh = connect()
    set_env_vars(ssh, {
        "MORPHEUS_OUTBOUND_PREFIX": "",
        "MORPHEUS_DIAL_METHOD": "api",
    })
    sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php artisan config:cache")
    enc = base64.b64encode(PHP.encode()).decode()
    out = sudo_run(ssh, f"cd {REMOTE_APP} && echo {enc} | base64 -d | sudo -u www-data php")
    grep = sudo_run(ssh, f"grep MORPHEUS_OUTBOUND_PREFIX {REMOTE_APP}/.env", check=False)
    ssh.close()
    print(json.dumps(json.loads(out), indent=2))
    print("ENV:", grep.strip())
    data = json.loads(out)
    ok = (data.get("outbound_prefix") or "") == "" and data.get("click_to_call_destination_sample") == "12722001232"
    print("OK" if ok else "CHECK")
    return 0 if ok else 1

if __name__ == "__main__":
    raise SystemExit(main())
