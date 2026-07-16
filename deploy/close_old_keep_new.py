#!/usr/bin/env python3
"""Stop ApexOne on OLD server; leave NEW server running."""

from __future__ import annotations

import shlex

import paramiko

OLD = {"host": "203.215.160.44", "user": "issac", "password": "SadamShah123"}
NEW = {"host": "203.215.161.236", "user": "ateg", "password": "balitech1"}
DOMAIN = "crm.apexonepayments.com"


def connect(cfg: dict) -> paramiko.SSHClient:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(cfg["host"], username=cfg["user"], password=cfg["password"], timeout=30)
    return ssh


def sudo(ssh: paramiko.SSHClient, password: str, cmd: str, timeout: int = 120) -> str:
    full = f"echo {shlex.quote(password)} | sudo -S -p '' bash -lc {shlex.quote(cmd)}"
    _, stdout, stderr = ssh.exec_command(full, timeout=timeout)
    return (stdout.read() + stderr.read()).decode(errors="replace")


def main() -> int:
    print("=== Confirm NEW server is healthy ===")
    new = connect(NEW)
    print(
        sudo(
            new,
            NEW["password"],
            f"""
systemctl is-active nginx php8.3-fpm mysql apexone-queue apex-call-events-ws
curl -sk -o /dev/null -w 'portal=%{{http_code}}\\n' -H 'Host: {DOMAIN}' --resolve {DOMAIN}:443:127.0.0.1 https://{DOMAIN}/portal/login
""",
        )
    )
    new.close()

    print("=== Stop ApexOne on OLD server (app closed; files/DB kept as backup) ===")
    old = connect(OLD)
    print(
        sudo(
            old,
            OLD["password"],
            f"""
set -e
# Stop app workers / websocket
systemctl stop apexone-queue.service apex-call-events-ws.service 2>/dev/null || true
systemctl stop apexone-comm-hub-monitor.timer apexone-comm-hub-monitor.service 2>/dev/null || true
systemctl disable apexone-queue.service apex-call-events-ws.service 2>/dev/null || true
systemctl disable apexone-comm-hub-monitor.timer 2>/dev/null || true

# Disable CRM nginx vhost (stop serving the domain here)
rm -f /etc/nginx/sites-enabled/apexone
# Minimal maintenance catch-all so leftover DNS hits don't serve the old app
cat >/etc/nginx/sites-available/apexone-closed <<'EOF'
server {{
    listen 80 default_server;
    listen [::]:80 default_server;
    listen 443 ssl http2 default_server;
    listen [::]:443 ssl http2 default_server;
    server_name {DOMAIN} _;

    ssl_certificate /etc/letsencrypt/live/{DOMAIN}/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/{DOMAIN}/privkey.pem;

    return 503;
    add_header Retry-After 3600 always;
    add_header Content-Type text/plain always;
}}
EOF
ln -sfn /etc/nginx/sites-available/apexone-closed /etc/nginx/sites-enabled/apexone-closed
rm -f /etc/nginx/sites-enabled/default 2>/dev/null || true
nginx -t && systemctl reload nginx

# Confirm workers are down
systemctl is-active apexone-queue || true
systemctl is-active apex-call-events-ws || true
ss -lptn 'sport = :8787' || true
curl -sk -o /dev/null -w 'old_https=%{{http_code}}\\n' -H 'Host: {DOMAIN}' https://127.0.0.1/portal/login || true
echo OLD_CLOSED_OK
# Keep MySQL/files in place as rollback backup — do not drop DB
ls -ld /var/www/apexone
""",
        )
    )
    old.close()

    print("=== Re-check NEW still live ===")
    new = connect(NEW)
    print(
        sudo(
            new,
            NEW["password"],
            f"""
systemctl is-active nginx php8.3-fpm mysql apexone-queue apex-call-events-ws
curl -sk -o /dev/null -w 'portal=%{{http_code}}\\n' -H 'Host: {DOMAIN}' --resolve {DOMAIN}:443:127.0.0.1 https://{DOMAIN}/portal/login
echo NEW_STILL_LIVE
""",
        )
    )
    new.close()
    print("Done. Old ApexOne closed; new server continues serving.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
