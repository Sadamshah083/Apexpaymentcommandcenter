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
export PATH="{APP}/node_modules/.bin:$PATH"
which vite || true
ls node_modules/.bin/vite
npm run build > /tmp/vite-hangup-ws.log 2>&1 || ./node_modules/.bin/vite build > /tmp/vite-hangup-ws.log 2>&1
echo BUILD_DONE:$?
tail -n 25 /tmp/vite-hangup-ws.log
chown -R www-data:www-data {APP}/public/build
sudo -u www-data php artisan view:clear
systemctl restart apex-call-events-ws.service
systemctl is-active apex-call-events-ws.service
# ensure built asset includes incoming-calls-disabled marker somehow via source timestamp / file mtime
stat -c '%y %n' resources/js/communications-webphone.js
stat -c '%y %n' public/build/manifest.json
python3 - <<'PY'
from pathlib import Path
import json
m = json.loads(Path('public/build/manifest.json').read_text())
keys = [k for k in m if 'communications-webphone' in k or 'webphone' in k]
print('manifest_webphone_keys', keys[:8])
for k in keys[:3]:
    p = Path('public/build') / m[k]['file']
    text = p.read_text(errors='replace')
    print(p.name, 'incoming_disabled=', 'incoming calls disabled' in text, 'size=', p.stat().st_size)
PY
"""
full = f"echo {shlex.quote(PW)} | sudo -S -p '' bash -lc {shlex.quote(cmd)}"
_, o, e = ssh.exec_command(full, timeout=600)
print((o.read() + e.read()).decode(errors="replace"))
ssh.close()
