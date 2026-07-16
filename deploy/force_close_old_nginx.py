#!/usr/bin/env python3
"""Force-disable CRM vhost on OLD server."""

from __future__ import annotations

import shlex

import paramiko

PW = "SadamShah123"
DOMAIN = "crm.apexonepayments.com"


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect("203.215.160.44", username="issac", password=PW, timeout=30)

    def sudo(cmd: str) -> str:
        full = f"echo {shlex.quote(PW)} | sudo -S -p '' bash -lc {shlex.quote(cmd)}"
        _, stdout, stderr = ssh.exec_command(full, timeout=60)
        return (stdout.read() + stderr.read()).decode(errors="replace")

    print(
        sudo(
            f"""
set -e
ls -la /etc/nginx/sites-enabled
# Remove every CRM site
rm -f /etc/nginx/sites-enabled/apexone /etc/nginx/sites-enabled/apexone-closed

# Simple HTTP+HTTPS closed response (use existing LE certs if present)
cat >/etc/nginx/sites-available/apexone-closed <<'EOF'
server {{
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name {DOMAIN} _;
    return 503 'ApexOne moved. Update DNS to the new server.';
    add_header Content-Type text/plain always;
}}

server {{
    listen 443 ssl http2 default_server;
    listen [::]:443 ssl http2 default_server;
    server_name {DOMAIN} _;
    ssl_certificate /etc/letsencrypt/live/{DOMAIN}/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/{DOMAIN}/privkey.pem;
    return 503 'ApexOne moved. Update DNS to the new server.';
    add_header Content-Type text/plain always;
}}
EOF
ln -sfn /etc/nginx/sites-available/apexone-closed /etc/nginx/sites-enabled/apexone-closed
nginx -t
systemctl reload nginx
sleep 1
echo '--- enabled ---'
ls -la /etc/nginx/sites-enabled
echo '--- curl ---'
curl -sk -o /tmp/oldbody.txt -w 'https=%{{http_code}}\\n' -H 'Host: {DOMAIN}' https://127.0.0.1/
head -c 200 /tmp/oldbody.txt; echo
curl -sk -o /dev/null -w 'portal=%{{http_code}}\\n' -H 'Host: {DOMAIN}' https://127.0.0.1/portal/login
# workers remain stopped
systemctl is-active apexone-queue || echo queue=stopped
systemctl is-active apex-call-events-ws || echo ws=stopped
echo FORCE_CLOSED_OK
"""
        )
    )
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
