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
cd /var/www/apexone
echo '=== .env morpheus dial keys ==='
grep -E 'MORPHEUS_|COMMUNICATIONS_|DIAL_METHOD|CAMPAIGN' .env | sed 's/=.*/=***/' 
echo
echo '=== laravel around 19:37 (originate screenshot) ==='
awk '/2026-07-15 19:3[5-9]/,/2026-07-15 19:4[0-2]/' storage/logs/laravel.log | grep -iE 'originate|morpheus|busy|extension|hangup|dial|campaign|webphone|Circuit|422|USER_BUSY|call-control|Comm hub' | tail -n 100
echo
echo '=== nginx access originate 422 ==='
grep 'calls/originate' /var/log/nginx/access.log 2>/dev/null | tail -n 20 || true
zgrep -h 'calls/originate' /var/log/nginx/access.log* 2>/dev/null | tail -n 30 || true
echo
echo '=== circuit breaker / morpheus recent ==='
grep -iE 'circuit|morpheus.*fail|originateCall|place.call|USER_BUSY|extension_offline|campaign_id' storage/logs/laravel.log | tail -n 40
"""
full = f"echo {shlex.quote(PW)} | sudo -S -p '' bash -lc {shlex.quote(cmd)}"
_, o, e = ssh.exec_command(full, timeout=60)
print((o.read() + e.read()).decode(errors="replace")[-15000:])
ssh.close()
