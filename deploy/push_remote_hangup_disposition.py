#!/usr/bin/env python3
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
REMOTE = "/var/www/apexone"
FILES = [
    "resources/js/communications-webphone.js",
    "resources/js/communications-auto-dial.js",
]


def main() -> int:
    sys.path.insert(0, str(ROOT))
    import deploy._ssh as ssh_mod

    ssh_mod.HOST = NEW["host"]
    ssh_mod.USER = NEW["user"]
    ssh_mod.PASSWORD = NEW["password"]
    ssh_mod.REMOTE_APP = REMOTE
    from deploy._ssh import upload_files

    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(NEW["host"], username=NEW["user"], password=NEW["password"], timeout=30)
    upload_files(ssh, [(ROOT / rel, rel) for rel in FILES], app_root=REMOTE)
    print("UPLOADED")

    inner = r"""
set -e
cd /var/www/apexone
echo SRC_REMOTE_HANGUP:$(grep -c remote-hangup resources/js/communications-webphone.js || true)
echo SRC_DISPO_GUARD:$(grep -c 'remoteHangupHandled' resources/js/communications-auto-dial.js || true)
./node_modules/.bin/vite build
echo BUILD_EXIT:$?
python3 - <<'PY'
from pathlib import Path
import json
m = json.loads(Path('public/build/manifest.json').read_text())
for k, v in m.items():
    f = str(v.get('file', ''))
    if f.endswith('.js') and 'communications' in f:
        t = (Path('public/build') / f).read_text(errors='replace')
        print(f, 'remote-hangup=', 'remote-hangup' in t, 'remoteHangupHandled=', 'remoteHangupHandled' in t)
PY
chown -R www-data:www-data /var/www/apexone/public/build
sudo -u www-data php artisan view:clear
echo DONE
"""
    cmd = f"echo {shlex.quote(NEW['password'])} | sudo -S -p '' bash -lc {shlex.quote(inner)}"
    _, o, e = ssh.exec_command(cmd, timeout=300)
    print(o.read().decode(errors="replace"))
    err = e.read().decode(errors="replace")
    if err.strip():
        print("---stderr---")
        print(err[-4000:])
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
