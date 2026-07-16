#!/usr/bin/env python3
import shlex
import paramiko

HOST = "203.215.161.236"
USER = "ateg"
PW = "balitech1"

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect(HOST, username=USER, password=PW, timeout=30)
cmd = r"""
set -e
grep -n 'async' /var/www/apexone/app/Http/Controllers/MorpheusHubController.php | head -n 8
grep -n 'incoming calls disabled' /var/www/apexone/resources/js/communications-webphone.js | head -n 3
grep -n 'websocket-hello' /var/www/apexone/services/call-events-ws/server.mjs | head -n 3
systemctl is-active apex-call-events-ws || true
ls -lt /var/www/apexone/public/build/manifest.json
tail -n 30 /tmp/vite-hangup-ws.log 2>/dev/null || echo NO_VITE_LOG
curl -fsS http://127.0.0.1:8787/health; echo
"""
full = f"echo {shlex.quote(PW)} | sudo -S -p '' bash -lc {shlex.quote(cmd)}"
_, o, e = ssh.exec_command(full, timeout=90)
print((o.read() + e.read()).decode(errors="replace"))
ssh.close()
