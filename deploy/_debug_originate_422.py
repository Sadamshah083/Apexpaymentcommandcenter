#!/usr/bin/env python3
"""Pull recent Laravel + nginx clues for originate 422 / call logs."""
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
cd /var/www/apexone
echo '=== recent originate / hangup / call log errors ==='
sudo -u www-data php artisan tinker --execute="echo 'ok';" 2>/dev/null | head -1 || true
# Laravel log
if [ -f storage/logs/laravel.log ]; then
  grep -E 'originate|Originate|hangup|Call log|call_logs|422|extension_busy|Could not place|Morpheus' storage/logs/laravel.log | tail -n 80
fi
echo
echo '=== laravel.log tail ==='
tail -n 60 storage/logs/laravel.log 2>/dev/null || true
echo
echo '=== check call logs route/controller timing ==='
grep -n "function recent\|callLogs\|call-logs\|call_logs" app/Http/Controllers/CommunicationsHubController.php routes/web.php 2>/dev/null | head -n 40
"""
full = f"echo {shlex.quote(PW)} | sudo -S -p '' bash -lc {shlex.quote(cmd)}"
_, o, e = ssh.exec_command(full, timeout=90)
print((o.read() + e.read()).decode(errors="replace")[-12000:])
ssh.close()
