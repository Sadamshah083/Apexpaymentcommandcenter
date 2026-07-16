#!/usr/bin/env python3
"""Finish NEW build/restart if prior deploy hung before logging."""
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
# rebuild if needed
if ! grep -q 'incoming calls disabled' resources/js/communications-webphone.js; then
  echo MISSING_JS
  exit 2
fi
npm run build > /tmp/vite-hangup-ws.log 2>&1
echo BUILD:$?
tail -n 15 /tmp/vite-hangup-ws.log
chown -R www-data:www-data {APP}/public/build
sudo -u www-data php artisan view:clear
sudo -u www-data php artisan config:clear
systemctl restart apex-call-events-ws.service
systemctl is-active apex-call-events-ws.service
# confirm built JS mentions hangup async path marker from controller still present in PHP
grep -n "async => true" app/Http/Controllers/MorpheusHubController.php | head
curl -fsS http://127.0.0.1:8787/health; echo
"""
full = f"echo {shlex.quote(PW)} | sudo -S -p '' bash -lc {shlex.quote(cmd)}"
_, o, e = ssh.exec_command(full, timeout=600)
print((o.read() + e.read()).decode(errors="replace"))
ssh.close()
