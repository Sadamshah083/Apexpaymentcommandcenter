#!/usr/bin/env python3
"""Deploy in-call Notes button open fix."""
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
    "resources/js/communications-phone-notes.js",
    "resources/js/app.js",
    "resources/css/comm-hub-ui-polish.css",
    "resources/css/comm-hub-ghl-theme.css",
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
./node_modules/.bin/vite build > /tmp/vite-notes-open.log 2>&1
echo BUILD:$?
tail -n 8 /tmp/vite-notes-open.log
chown -R www-data:www-data {REMOTE_APP}/public/build
# Confirm markers land in built JS/CSS bundles
grep -l "activeCallNotesBound" public/build/assets/*.js | head -3
grep -n "is-notes-open:not(.hidden)" resources/css/comm-hub-ui-polish.css | head -3
grep -n "activeCallNotesBound" resources/js/communications-phone-notes.js | head -3
echo DONE
"""
    full = f"echo {shlex.quote(NEW['password'])} | sudo -S -p '' bash -lc {shlex.quote(cmd)}"
    _, o, e = ssh.exec_command(full, timeout=180)
    print((o.read() + e.read()).decode(errors="replace"))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
