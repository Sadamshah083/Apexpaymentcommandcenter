#!/usr/bin/env python3
"""Deploy call-monitoring /live cancel fix (double-boot)."""

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
    "resources/js/app.js",
    "resources/js/call-monitoring.js",
]


def main() -> int:
    text = (ROOT / "resources/js/app.js").read_text(encoding="utf-8")
    if "deferredBootGeneration" not in text:
        raise SystemExit("FAILED: deferredBootGeneration missing from app.js")
    mon = (ROOT / "resources/js/call-monitoring.js").read_text(encoding="utf-8")
    if "monitoringRuntime.pollUrl === pollUrl" not in mon:
        raise SystemExit("FAILED: init guard missing from call-monitoring.js")

    ssh = connect()
    upload_files(ssh, [(ROOT / f, f) for f in FILES], REMOTE_APP)
    print(
        sudo_run(
            ssh,
            f"""
cd {REMOTE_APP}
npm run build > /tmp/vite-live-cancel.log 2>&1
tail -n 20 /tmp/vite-live-cancel.log
echo BUILD:$?
chown -R www-data:www-data {REMOTE_APP}/public/build
grep -n "deferredBootGeneration\\|pollUrl === pollUrl\\|NAV_POLL_MS = 12000" \\
  resources/js/app.js resources/js/call-monitoring.js | head -20
# Smoke the live endpoint as www-data (auth may fail without session — just php lint + route)
sudo -u www-data php artisan route:list --path=monitoring/live 2>/dev/null | head -10
""",
            check=False,
        )
    )
    ssh.close()
    print("Deployed live-poll cancel fix. Hard refresh (Ctrl+F5).")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
