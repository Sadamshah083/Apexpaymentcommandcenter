#!/usr/bin/env python3
"""Deploy AI call summary feature to NEW production server only."""
from __future__ import annotations

import os
import sys
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

import deploy._ssh as ssh_mod
from deploy._ssh import connect, sudo_run, upload_files

NEW_HOST = "203.215.161.236"
NEW_USER = "ateg"
NEW_PASS = "balitech1"

FILES = [
    "app/Services/Communications/CallRecordingSummaryService.php",
    "app/Http/Controllers/AgentStatusReportController.php",
    "app/Services/Communications/AgentStatusReportService.php",
    "resources/views/communications/agent-status/partials/panel.blade.php",
    "resources/css/app.css",
    "routes/web.php",
]


def main() -> int:
    ssh_mod.HOST = NEW_HOST
    ssh_mod.USER = NEW_USER
    ssh_mod.PASSWORD = NEW_PASS
    ssh_mod.REMOTE_APP = "/var/www/apexone"
    os.environ["DEPLOY_PASSWORD"] = NEW_PASS

    ssh = None
    for attempt in range(1, 6):
        try:
            print(f"CONNECT attempt {attempt}/5 -> {NEW_HOST}")
            ssh = connect(timeout=18)
            print("CONNECTED")
            break
        except Exception as e:
            print(f"CONNECT_FAIL: {e}")
            if attempt < 5:
                time.sleep(12)

    if ssh is None:
        print("LIVE_FAIL new server unreachable")
        return 1

    try:
        upload_files(ssh, [(ROOT / rel, rel) for rel in FILES], app_root="/var/www/apexone")
        # Build frontend assets if npm available; always clear caches.
        out = sudo_run(
            ssh,
            "cd /var/www/apexone && "
            "php -l app/Services/Communications/CallRecordingSummaryService.php && "
            "php -l app/Http/Controllers/AgentStatusReportController.php && "
            "grep -q agent-status.summary routes/web.php && echo route_ok && "
            "(test -f package.json && npm run build --silent 2>/dev/null || echo skip_vite) && "
            "php artisan view:clear && php artisan route:clear && php artisan config:clear && "
            "php artisan cache:clear && "
            "curl -s -o /dev/null -w 'local:%{http_code} t:%{time_total}\\n' --max-time 20 http://127.0.0.1/admin/login || true",
        )
        print(out.encode("ascii", "replace").decode("ascii"))
        print("LIVE_OK")
        return 0
    finally:
        ssh.close()


if __name__ == "__main__":
    raise SystemExit(main())
