#!/usr/bin/env python3
"""Install ApexOne auto-restart watchdog + systemd Restart=always on core services."""
from __future__ import annotations

import os
import shlex
import sys
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]
NEW = {
    "host": "203.215.161.236",
    "user": "ateg",
    "password": os.environ.get("NEW_DEPLOY_PASSWORD", "balitech1"),
}
REMOTE_APP = "/var/www/apexone"

FILES = [
    "scripts/apexone_watchdog.sh",
    "deploy/apexone-watchdog.service",
    "deploy/apexone-watchdog.timer",
    "deploy/apexone-queue.service",
    "services/call-events-ws/apex-call-events-ws.service",
    "deploy/systemd-dropins/nginx-restart.conf",
    "deploy/systemd-dropins/php83-fpm-restart.conf",
]


def main() -> int:
    sys.path.insert(0, str(ROOT))
    os.environ["DEPLOY_HOST"] = NEW["host"]
    os.environ["DEPLOY_USER"] = NEW["user"]
    os.environ["DEPLOY_PASSWORD"] = NEW["password"]
    import deploy._ssh as ssh_mod

    ssh_mod.HOST = NEW["host"]
    ssh_mod.USER = NEW["user"]
    ssh_mod.PASSWORD = NEW["password"]
    ssh_mod.REMOTE_APP = REMOTE_APP
    from deploy._ssh import upload_files

    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(NEW["host"], username=NEW["user"], password=NEW["password"], timeout=30)
    upload_files(ssh, [(ROOT / rel, rel) for rel in FILES], app_root=REMOTE_APP)

    cmd = f"""
set -e
APP={REMOTE_APP}
chmod +x "$APP/scripts/apexone_watchdog.sh"
# Normalize Windows CRLF if present
sed -i 's/\r$//' "$APP/scripts/apexone_watchdog.sh"
chown www-data:www-data "$APP/scripts/apexone_watchdog.sh"

# Core app units
cp "$APP/deploy/apexone-queue.service" /etc/systemd/system/apexone-queue.service
cp "$APP/services/call-events-ws/apex-call-events-ws.service" /etc/systemd/system/apex-call-events-ws.service
cp "$APP/deploy/apexone-watchdog.service" /etc/systemd/system/apexone-watchdog.service
cp "$APP/deploy/apexone-watchdog.timer" /etc/systemd/system/apexone-watchdog.timer

# nginx / php-fpm auto-restart drop-ins
mkdir -p /etc/systemd/system/nginx.service.d
mkdir -p /etc/systemd/system/php8.3-fpm.service.d
cp "$APP/deploy/systemd-dropins/nginx-restart.conf" /etc/systemd/system/nginx.service.d/restart.conf
cp "$APP/deploy/systemd-dropins/php83-fpm-restart.conf" /etc/systemd/system/php8.3-fpm.service.d/restart.conf

systemctl daemon-reload

# Enable + start everything
systemctl enable nginx php8.3-fpm apexone-queue apex-call-events-ws apexone-watchdog.timer
systemctl restart apexone-queue apex-call-events-ws
systemctl restart nginx php8.3-fpm
systemctl enable --now apexone-watchdog.timer
systemctl start apexone-watchdog.service

# Keep existing comm-hub monitor timer as well
systemctl enable --now apexone-comm-hub-monitor.timer 2>/dev/null || true

echo '===== status ====='
systemctl is-enabled nginx php8.3-fpm apexone-queue apex-call-events-ws apexone-watchdog.timer
systemctl is-active nginx php8.3-fpm apexone-queue apex-call-events-ws apexone-watchdog.timer
systemctl show nginx -p Restart --value
systemctl show php8.3-fpm -p Restart --value
systemctl show apexone-queue -p Restart --value
systemctl show apex-call-events-ws -p Restart --value

echo '===== watchdog run ====='
bash "$APP/scripts/apexone_watchdog.sh" || true
tail -n 20 "$APP/storage/logs/apexone-watchdog.log" 2>/dev/null || true

echo '===== health ====='
curl -sk -o /dev/null -w 'up=%{{http_code}}\\n' -H 'Host: crm.apexonepayments.com' https://127.0.0.1/up
curl -fsS http://127.0.0.1:8787/health || true
echo
systemctl list-timers --all | grep -E 'apexone-watchdog|apexone-comm' || true
echo DONE
"""
    full = f"echo {shlex.quote(NEW['password'])} | sudo -S -p '' bash -lc {shlex.quote(cmd)}"
    _, o, e = ssh.exec_command(full, timeout=180)
    print((o.read() + e.read()).decode(errors="replace"))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
