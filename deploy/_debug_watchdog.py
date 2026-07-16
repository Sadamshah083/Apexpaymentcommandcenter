#!/usr/bin/env python3
import paramiko
import shlex

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect("203.215.161.236", username="ateg", password="balitech1", timeout=25)
cmd = r"""
journalctl -u apexone-watchdog.service -n 40 --no-pager
echo '---'
file /var/www/apexone/scripts/apexone_watchdog.sh
head -5 /var/www/apexone/scripts/apexone_watchdog.sh | od -c | head -20
bash -n /var/www/apexone/scripts/apexone_watchdog.sh; echo bash_n=$?
bash /var/www/apexone/scripts/apexone_watchdog.sh; echo run=$?
"""
full = f"echo {shlex.quote('balitech1')} | sudo -S -p '' bash -lc {shlex.quote(cmd)}"
_, o, e = ssh.exec_command(full, timeout=90)
print((o.read() + e.read()).decode(errors="replace"))
ssh.close()
