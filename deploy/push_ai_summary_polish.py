#!/usr/bin/env python3
"""Deploy AI summary UX polish to NEW server only."""
from __future__ import annotations

import os
import sys
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

import deploy._ssh as ssh_mod
from deploy._ssh import connect, sudo_run, upload_files

FILES = [
    "app/Services/Communications/CallRecordingSummaryService.php",
    "app/Services/Communications/AgentStatusReportService.php",
    "app/Http/Controllers/AgentStatusReportController.php",
    "resources/views/communications/agent-status/partials/panel.blade.php",
    "resources/css/app.css",
    "routes/web.php",
]


def main() -> int:
    ssh_mod.HOST = "203.215.161.236"
    ssh_mod.USER = "ateg"
    ssh_mod.PASSWORD = "balitech1"
    ssh_mod.REMOTE_APP = "/var/www/apexone"
    os.environ["DEPLOY_PASSWORD"] = "balitech1"

    ssh = None
    for attempt in range(1, 5):
        try:
            print(f"CONNECT {attempt}")
            ssh = connect(timeout=18)
            break
        except Exception as e:
            print(f"FAIL {e}")
            time.sleep(10)
    if ssh is None:
        print("LIVE_FAIL")
        return 1

    try:
        upload_files(ssh, [(ROOT / rel, rel) for rel in FILES], app_root="/var/www/apexone")
        out = sudo_run(
            ssh,
            "cd /var/www/apexone && "
            "php -l app/Services/Communications/CallRecordingSummaryService.php && "
            "php -l app/Services/Communications/AgentStatusReportService.php && "
            "php -l app/Http/Controllers/AgentStatusReportController.php && "
            "grep -q export-summaries routes/web.php && echo route_ok && "
            "npm run build --silent && "
            "php artisan view:clear && php artisan route:clear && php artisan config:clear && "
            "php artisan cache:clear && "
            "php artisan tinker --execute=\"Cache::increment('agent_status:cache_version:w2'); echo 'cache_bumped';\" 2>/dev/null || true",
        )
        print(out.encode("ascii", "replace").decode("ascii"))
        print("LIVE_OK")
        return 0
    finally:
        ssh.close()


if __name__ == "__main__":
    raise SystemExit(main())
