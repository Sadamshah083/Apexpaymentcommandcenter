#!/usr/bin/env python3
"""Deploy Call Monitoring dead + disposition boards."""

from __future__ import annotations

import os
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

_pw = Path(__file__).with_name(".deploy_password")
if _pw.exists() and not os.environ.get("DEPLOY_PASSWORD"):
    os.environ["DEPLOY_PASSWORD"] = _pw.read_text(encoding="utf-8").strip()

import deploy._ssh as ssh_mod

ssh_mod.PASSWORD = os.environ.get("DEPLOY_PASSWORD", "") or ssh_mod.PASSWORD

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

FILES = [
    "app/Services/Communications/CallMonitoringService.php",
    "app/Services/Communications/AgentPresenceService.php",
    "app/Http/Controllers/CallMonitoringController.php",
    "resources/views/communications/monitoring/partials/wallboard.blade.php",
    "resources/views/communications/monitoring/partials/table-section.blade.php",
    "resources/views/communications/monitoring/partials/row.blade.php",
    "resources/js/call-monitoring.js",
    "resources/js/communications-auto-dial.js",
    "resources/css/app.css",
]


def main() -> int:
    mon = (ROOT / "app/Services/Communications/CallMonitoringService.php").read_text(encoding="utf-8")
    if "buildDispositionRows" not in mon or "recentDeadCallRows" not in mon:
        raise SystemExit("FAILED: disposition/dead builders missing")
    wall = (ROOT / "resources/views/communications/monitoring/partials/wallboard.blade.php").read_text(
        encoding="utf-8"
    )
    if "Dead call" not in wall or "Disposition" not in wall:
        raise SystemExit("FAILED: wallboard sections missing")

    ssh = connect()
    upload_files(ssh, [(ROOT / f, f) for f in FILES], REMOTE_APP)
    print(
        sudo_run(
            ssh,
            f"""
cd {REMOTE_APP}
php -l app/Services/Communications/CallMonitoringService.php
php -l app/Services/Communications/AgentPresenceService.php
php -l app/Http/Controllers/CallMonitoringController.php
npm run build > /tmp/vite-monitoring-dead-disp.log 2>&1
tail -n 22 /tmp/vite-monitoring-dead-disp.log
echo BUILD:$?
chown -R www-data:www-data {REMOTE_APP}/public/build
sudo -u www-data php artisan view:clear
grep -n "Dead call\\|Disposition\\|buildDispositionRows\\|in_disposition\\|board__indicator" \\
  resources/views/communications/monitoring/partials/wallboard.blade.php \\
  app/Services/Communications/CallMonitoringService.php \\
  resources/js/communications-auto-dial.js \\
  resources/css/app.css | head -40
""",
            check=False,
        )
    )
    ssh.close()
    print("Deployed dead+disposition monitoring. Ctrl+F5 Call Monitoring.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
