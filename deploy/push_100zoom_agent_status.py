#!/usr/bin/env python3
"""Deploy 100%-zoom friendly agent status / monitoring layout."""
from __future__ import annotations

import os
import shlex
import sys
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]
NEW = {
    "host": "203.215.161.236",
    "user": "ateg",
    "password": os.environ.get("NEW_DEPLOY_PASSWORD", "balitech1"),
}
REMOTE_APP = "/var/www/apexone"
FILES = [
    "resources/css/app.css",
    "resources/views/communications/agent-status/partials/panel.blade.php",
    "resources/views/communications/monitoring/partials/wallboard.blade.php",
]


def main() -> int:
    sys.path.insert(0, str(ROOT))
    os.environ["DEPLOY_HOST"] = NEW["host"]
    os.environ["DEPLOY_USER"] = NEW["user"]
    os.environ["DEPLOY_PASSWORD"] = NEW["password"]
    import deploy._ssh as ssh_mod

    ssh_mod.HOST = NEW["host"]
    ssh_mod.USER = NEW["user"]
    ssh_mod.PASSWORD = NEW["password"]
    ssh_mod.REMOTE_APP = REMOTE_APP
    from deploy._ssh import upload_files

    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(NEW["host"], username=NEW["user"], password=NEW["password"], timeout=30)
    upload_files(ssh, [(ROOT / rel, rel) for rel in FILES], app_root=REMOTE_APP)

    cmd = f"""
set -e
cd {REMOTE_APP}
./node_modules/.bin/vite build > /tmp/vite-100zoom.log 2>&1
echo BUILD:$?
tail -n 5 /tmp/vite-100zoom.log
chown -R www-data:www-data {REMOTE_APP}/public/build
sudo -u www-data php artisan view:clear
grep -n "agent-status-toolbar" resources/views/communications/agent-status/partials/panel.blade.php | head -3
grep -n "call-monitoring-toolbar" resources/views/communications/monitoring/partials/wallboard.blade.php | head -3
grep -n "fit at 100%" resources/css/app.css | head -2
echo DONE
"""
    full = f"echo {shlex.quote(NEW['password'])} | sudo -S -p '' bash -lc {shlex.quote(cmd)}"
    _, o, e = ssh.exec_command(full, timeout=180)
    print((o.read() + e.read()).decode(errors="replace"))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
