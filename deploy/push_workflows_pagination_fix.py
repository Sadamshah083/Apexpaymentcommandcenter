#!/usr/bin/env python3
"""Deploy workflows pagination hang + page indicator contrast fixes."""
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
    "app/Http/Controllers/WorkflowController.php",
    "app/Services/Workflow/WorkflowDashboardService.php",
    "resources/views/workflows/index.blade.php",
    "resources/css/app.css",
    "resources/js/pagination-preserve.js",
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
./node_modules/.bin/vite build > /tmp/vite-workflows-page.log 2>&1
echo BUILD:$?
tail -n 6 /tmp/vite-workflows-page.log
chown -R www-data:www-data {REMOTE_APP}/public/build
sudo -u www-data php artisan view:clear
sudo -u www-data php artisan config:clear
grep -n "files_page\\|never GROUP BY" app/Http/Controllers/WorkflowController.php | head -5
grep -n "latest('id')" app/Services/Workflow/WorkflowDashboardService.php | head -3
grep -n "e2e8f0 !important" resources/css/app.css | head -3
echo DONE
"""
    full = f"echo {shlex.quote(NEW['password'])} | sudo -S -p '' bash -lc {shlex.quote(cmd)}"
    _, o, e = ssh.exec_command(full, timeout=180)
    print((o.read() + e.read()).decode(errors="replace"))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
