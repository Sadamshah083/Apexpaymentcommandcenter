#!/usr/bin/env python3
"""Probe production systemd units and health for auto-restart setup."""
from __future__ import annotations

import shlex

import paramiko

HOST, USER, PW = "203.215.161.236", "ateg", "balitech1"


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PW, timeout=30)
    cmd = r"""
set -e
echo '===== units ====='
systemctl list-units --type=service --all | grep -Ei 'apex|queue|nginx|php|fpm|ws' || true
echo
echo '===== unit files ====='
ls -la /etc/systemd/system/apex* /etc/systemd/system/*queue* 2>/dev/null || true
echo
echo '===== apexone-queue ====='
systemctl cat apexone-queue.service 2>/dev/null || echo MISSING
echo
echo '===== call-events-ws ====='
systemctl cat apex-call-events-ws.service 2>/dev/null || echo MISSING
echo
echo '===== monitor timer ====='
systemctl cat apexone-comm-hub-monitor.timer 2>/dev/null || echo MISSING_TIMER
systemctl cat apexone-comm-hub-monitor.service 2>/dev/null || echo MISSING_SVC
echo
echo '===== enabled ====='
systemctl is-enabled nginx php8.3-fpm apexone-queue apex-call-events-ws 2>/dev/null || true
systemctl is-active nginx php8.3-fpm apexone-queue apex-call-events-ws 2>/dev/null || true
echo
echo '===== health ====='
curl -fsS -o /dev/null -w 'local_up=%{http_code}\n' http://127.0.0.1/up || curl -fsS -o /dev/null -w 'local_up_fail=%{http_code}\n' http://127.0.0.1:80/up || true
curl -fsS -o /dev/null -w 'public_up=%{http_code}\n' https://crm.apexonepayments.com/up || true
curl -fsS http://127.0.0.1:8787/health || true
echo
echo '===== restart policies ====='
systemctl show nginx php8.3-fpm apexone-queue apex-call-events-ws -p Id -p ActiveState -p UnitFileState -p Restart -p RestartUSec 2>/dev/null || true
"""
    full = f"echo {shlex.quote(PW)} | sudo -S -p '' bash -lc {shlex.quote(cmd)}"
    _, o, e = ssh.exec_command(full, timeout=90)
    print((o.read() + e.read()).decode(errors="replace"))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
