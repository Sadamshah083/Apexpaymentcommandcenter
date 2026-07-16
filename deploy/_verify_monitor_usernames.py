#!/usr/bin/env python3
import paramiko
import shlex

HOST, USER, PW = "203.215.161.236", "ateg", "balitech1"
cmd = r"""
cd /var/www/apexone
echo "resolver=$(grep -c resolveAgentDisplayName app/Services/Communications/CallMonitoringService.php)"
echo "name_css=$(grep -n 'font-weight: 600' resources/css/app.css | head -1)"
ls -lt public/build/assets/call-monitoring-*.js | head -2
echo OK
"""
ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect(HOST, username=USER, password=PW, timeout=25)
full = f"echo {shlex.quote(PW)} | sudo -S -p '' bash -lc {shlex.quote(cmd)}"
_, o, e = ssh.exec_command(full, timeout=60)
print((o.read() + e.read()).decode(errors="replace"))
ssh.close()
