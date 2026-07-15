#!/usr/bin/env python3
"""Deploy unified Call Monitoring table."""

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
    "resources/views/communications/monitoring/partials/wallboard.blade.php",
    "resources/js/call-monitoring.js",
    "resources/css/app.css",
]


def main() -> int:
    wall = (ROOT / FILES[0]).read_text(encoding="utf-8")
    if 'data-call-monitoring-rows="all"' not in wall:
        raise SystemExit("FAILED: unified tbody missing")
    js = (ROOT / FILES[1]).read_text(encoding="utf-8")
    if "sortUnifiedRows" not in js or "fillTable(root, 'all'" not in js:
        raise SystemExit("FAILED: unified JS missing")

    ssh = connect()
    upload_files(ssh, [(ROOT / f, f) for f in FILES], REMOTE_APP)
    print(
        sudo_run(
            ssh,
            f"""
cd {REMOTE_APP}
npm run build > /tmp/vite-monitoring-unified.log 2>&1
tail -n 20 /tmp/vite-monitoring-unified.log
echo BUILD:$?
chown -R www-data:www-data {REMOTE_APP}/public/build
sudo -u www-data php artisan view:clear
grep -n "data-call-monitoring-rows=\\"all\\"|sortUnifiedRows|indicator--unified" \\
  resources/views/communications/monitoring/partials/wallboard.blade.php \\
  resources/js/call-monitoring.js \\
  resources/css/app.css | head -25
""",
            check=False,
        )
    )
    ssh.close()
    print("Unified Call Monitoring deployed. Ctrl+F5.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
