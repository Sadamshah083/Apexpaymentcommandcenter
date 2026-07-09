#!/usr/bin/env python3
"""Diagnose why CRM dial is not ringing."""
from __future__ import annotations
import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run

def main() -> int:
    ssh = connect()
    env = sudo_run(ssh, "grep -E 'MORPHEUS_(DIAL_METHOD|WEBPHONE_DIAL_MODE|ORIGINATE_METHOD|WEBPHONE_AUTO_ANSWER)' /var/www/apexone/.env || true")
    print("=== .env ===")
    print(env)

    cfg = sudo_run(
        ssh,
        f"cd {REMOTE_APP} && sudo -u www-data php -r \""
        "require 'vendor/autoload.php';"
        "$app=require 'bootstrap/app.php';"
        "$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();"
        "echo json_encode(["
        "'dial_mode'=>config('integrations.morpheus.webphone_dial_mode'),"
        "'dial_method'=>config('integrations.morpheus.dial_method'),"
        "'originate_method'=>config('integrations.morpheus.originate_method'),"
        "'auto_answer'=>config('integrations.morpheus.webphone_auto_answer'),"
        "]);\""
    )
    print("=== config ===")
    print(cfg)

    log = sudo_run(ssh, f"tail -50 {REMOTE_APP}/storage/logs/laravel.log 2>/dev/null | tail -20")
    print("=== recent laravel log ===")
    print(log)

    # ext 1020 endpoint + recent CDR via artisan if script exists
    ring = sudo_run(
        ssh,
        f"cd {REMOTE_APP} && sudo -u www-data php scripts/verify_click_to_call_ring.php 1020 12722001232 8 2>&1 | tail -25"
    )
    print("=== click-to-call test ext 1020 ===")
    print(ring)

    ssh.close()
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
