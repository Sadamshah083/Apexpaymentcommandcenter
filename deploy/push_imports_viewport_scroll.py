#!/usr/bin/env python3
from __future__ import annotations

import sys
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]
NEW = {"host": "203.215.161.236", "user": "ateg", "password": "balitech1"}
REMOTE = "/var/www/apexone"
FILES = ["resources/css/app.css"]


def main() -> int:
    # ensure CSS block is present
    import subprocess
    subprocess.check_call([sys.executable, str(ROOT / "deploy" / "_append_imports_viewport_scroll.py")])

    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(NEW["host"], username=NEW["user"], password=NEW["password"], timeout=40)

    sys.path.insert(0, str(ROOT))
    import deploy._ssh as ssh_mod
    from deploy._ssh import sudo_run, upload_files

    ssh_mod.HOST = NEW["host"]
    ssh_mod.USER = NEW["user"]
    ssh_mod.PASSWORD = NEW["password"]
    ssh_mod.REMOTE_APP = REMOTE

    print("Uploading CSS...")
    upload_files(ssh, [(ROOT / rel, rel) for rel in FILES], app_root=REMOTE)
    inner = f"""
set -e
cd {REMOTE}
chown www-data:www-data {REMOTE}/resources/css/app.css
npm run build --silent
grep -n "Imported Leads viewport scroll shell\\|overflow-y: auto" resources/css/app.css | tail -15
echo DONE_IMPORTS_VIEWPORT_SCROLL
"""
    print(sudo_run(ssh, inner))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
