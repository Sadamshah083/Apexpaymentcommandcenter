#!/usr/bin/env python3
import shlex
import paramiko

HOST = "203.215.161.236"
USER = "ateg"
PW = "balitech1"
APP = "/var/www/apexone"

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect(HOST, username=USER, password=PW, timeout=30)

cmd = f"""
set -e
cd {APP}
node -v
npm -v
ls package.json
# install deps if vite missing
if [ ! -x node_modules/.bin/vite ]; then
  echo INSTALLING_NPM
  npm ci 2>/tmp/npm-ci.err || npm install --no-audit --no-fund 2>/tmp/npm-install.err
  tail -n 20 /tmp/npm-ci.err /tmp/npm-install.err 2>/dev/null || true
fi
ls -la node_modules/.bin/vite
./node_modules/.bin/vite build > /tmp/vite-hangup-ws.log 2>&1
echo BUILD:$?
tail -n 30 /tmp/vite-hangup-ws.log
chown -R www-data:www-data {APP}/public/build {APP}/node_modules || true
sudo -u www-data php artisan view:clear
systemctl restart apex-call-events-ws.service
systemctl is-active apex-call-events-ws.service
stat -c '%y %n' public/build/manifest.json
python3 - <<'PY'
from pathlib import Path
import json
m = json.loads(Path('public/build/manifest.json').read_text())
for k, v in m.items():
    if 'webphone' in k or 'communications' in k:
        p = Path('public/build') / v['file']
        text = p.read_text(errors='replace') if p.exists() else ''
        print(k, '->', v['file'], 'incoming=', 'incoming calls disabled' in text)
PY
"""
full = f"echo {shlex.quote(PW)} | sudo -S -p '' bash -lc {shlex.quote(cmd)}"
_, o, e = ssh.exec_command(full, timeout=900)
print((o.read() + e.read()).decode(errors="replace"))
ssh.close()
