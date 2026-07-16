#!/usr/bin/env python3
import os
import shlex
import paramiko

NEW = {"host": "203.215.161.236", "user": "ateg", "password": os.environ.get("NEW_DEPLOY_PASSWORD", "balitech1")}
REMOTE = "/var/www/apexone"
ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect(NEW["host"], username=NEW["user"], password=NEW["password"], timeout=30)

inner = f"""
cd {REMOTE}
set -x
grep -c remote-hangup resources/js/communications-webphone.js || true
./node_modules/.bin/vite build
echo BUILD_EXIT:$?
ls -lt public/build/assets/communications*.js | head -5
python3 - <<'PY'
from pathlib import Path
import json
m=json.loads(Path('public/build/manifest.json').read_text())
for k,v in m.items():
    f=str(v.get('file',''))
    if f.endswith('.js') and 'communications' in f:
        t=(Path('public/build')/f).read_text(errors='replace')
        print(f, 'remote-hangup' in t, 'remoteHangupHandled' in t)
PY
chown -R www-data:www-data public/build
sudo -u www-data php artisan view:clear
"""
cmd = f"echo {shlex.quote(NEW['password'])} | sudo -S -p '' bash -lc {shlex.quote(inner)}"
_, o, e = ssh.exec_command(cmd, timeout=300)
print(o.read().decode(errors="replace"))
print(e.read().decode(errors="replace")[-4000:])
ssh.close()
