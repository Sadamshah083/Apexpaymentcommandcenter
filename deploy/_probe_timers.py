#!/usr/bin/env python3
import paramiko
import shlex

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect("203.215.161.236", username="ateg", password="balitech1", timeout=25)
cmd = r"""
systemctl is-enabled apexone-comm-hub-monitor.timer; systemctl is-active apexone-comm-hub-monitor.timer
systemctl list-timers --all | grep -i apex || true
systemctl is-active mysql mariadb 2>/dev/null || true
curl -sI -m 5 http://127.0.0.1/up | head -5
curl -sk -m 5 -o /dev/null -w 'https_local=%{http_code}\n' https://127.0.0.1/up -H 'Host: crm.apexonepayments.com'
"""
full = f"echo {shlex.quote('balitech1')} | sudo -S -p '' bash -lc {shlex.quote(cmd)}"
_, o, e = ssh.exec_command(full, timeout=60)
print((o.read() + e.read()).decode(errors="replace"))
ssh.close()
