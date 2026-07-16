#!/usr/bin/env python3
"""Deploy hangup WS close + remove post-bye status polling."""
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
FILES = ["resources/js/communications-webphone.js"]


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
    pairs = [(ROOT / rel, rel) for rel in FILES]
    print(f"Uploading {len(pairs)} files...")
    upload_files(ssh, pairs, app_root=REMOTE_APP)

    cmd = f"""
set -e
cd {REMOTE_APP}
./node_modules/.bin/vite build > /tmp/vite-ws-hangup.log 2>&1
echo BUILD:$?
tail -n 10 /tmp/vite-ws-hangup.log
chown -R www-data:www-data {REMOTE_APP}/public/build
sudo -u www-data php artisan view:clear
python3 - <<'PY'
from pathlib import Path
import json
m=json.loads(Path('public/build/manifest.json').read_text())
for k,v in m.items():
    if 'communications' in k and str(v.get('file','')).endswith('.js') and 'auto-dial' not in k:
        p=Path('public/build')/v['file']
        t=p.read_text(errors='replace')
        print(p.name)
        print('  close hangup=', "close(1000" in t or "hangup" in t and "readyState" in t)
        print('  no post-bye-poll=', 'post-bye-poll' not in t)
        print('  no 45s poll=', '45000' not in t and '45_000' not in t)
PY
echo DONE
"""
    full = f"echo {shlex.quote(NEW['password'])} | sudo -S -p '' bash -lc {shlex.quote(cmd)}"
    _, o, e = ssh.exec_command(full, timeout=180)
    print((o.read() + e.read()).decode(errors="replace"))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
