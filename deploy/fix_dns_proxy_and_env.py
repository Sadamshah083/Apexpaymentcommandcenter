#!/usr/bin/env python3
"""
1) Fix NEW server .env (APP_URL + caches)
2) Point OLD nginx at NEW IP as reverse-proxy so crm.apexonepayments.com works
   while DNS still resolves to 203.215.160.44
"""

from __future__ import annotations

import shlex

import paramiko

OLD = {"host": "203.215.160.44", "user": "issac", "password": "SadamShah123"}
NEW = {"host": "203.215.161.236", "user": "ateg", "password": "balitech1"}
DOMAIN = "crm.apexonepayments.com"
NEW_IP = "203.215.161.236"


def connect(cfg: dict) -> paramiko.SSHClient:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(cfg["host"], username=cfg["user"], password=cfg["password"], timeout=30)
    return ssh


def sudo(ssh: paramiko.SSHClient, password: str, cmd: str, timeout: int = 180) -> str:
    full = f"echo {shlex.quote(password)} | sudo -S -p '' bash -lc {shlex.quote(cmd)}"
    _, stdout, stderr = ssh.exec_command(full, timeout=timeout)
    return (stdout.read() + stderr.read()).decode(errors="replace")


def main() -> int:
    print("=== 1) Update NEW server .env + caches ===")
    new = connect(NEW)
    print(
        sudo(
            new,
            NEW["password"],
            f"""
set -e
cd /var/www/apexone
# Keep domain URL; ensure production URL is correct (no old IP)
if grep -q '^APP_URL=' .env; then
  sed -i 's|^APP_URL=.*|APP_URL=https://{DOMAIN}|' .env
else
  echo 'APP_URL=https://{DOMAIN}' >> .env
fi
# Session/cookie for HTTPS domain
sed -i 's|^SESSION_SECURE_COOKIE=.*|SESSION_SECURE_COOKIE=true|' .env || echo 'SESSION_SECURE_COOKIE=true' >> .env
# Trust proxied requests from temporary old-IP nginx hop
if grep -q '^TRUSTED_PROXIES=' .env; then
  sed -i 's|^TRUSTED_PROXIES=.*|TRUSTED_PROXIES=*|' .env
else
  echo 'TRUSTED_PROXIES=*' >> .env
fi
grep -E '^(APP_URL|APP_ENV|SESSION_SECURE_COOKIE|TRUSTED_PROXIES)=' .env
chown www-data:www-data .env
chmod 640 .env
sudo -u www-data php artisan config:clear
sudo -u www-data php artisan cache:clear || true
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
systemctl restart php8.3-fpm nginx apexone-queue apex-call-events-ws
curl -sk -o /dev/null -w 'new_portal=%{{http_code}}\\n' -H 'Host: {DOMAIN}' --resolve {DOMAIN}:443:127.0.0.1 https://{DOMAIN}/portal/login
curl -sk -o /dev/null -w 'new_admin=%{{http_code}}\\n' -H 'Host: {DOMAIN}' --resolve {DOMAIN}:443:127.0.0.1 https://{DOMAIN}/admin/login
echo NEW_ENV_OK
""",
        )
    )
    new.close()

    print("=== 2) OLD nginx → reverse proxy to NEW IP (fixes live DNS still on old IP) ===")
    old = connect(OLD)
    print(
        sudo(
            old,
            OLD["password"],
            f"""
set -e
# Keep queue/ws stopped on old — only proxy HTTP(S)
systemctl stop apexone-queue apex-call-events-ws 2>/dev/null || true
systemctl disable apexone-queue apex-call-events-ws 2>/dev/null || true

cat >/etc/nginx/sites-available/apexone-proxy <<'EOF'
map $http_upgrade $connection_upgrade {{
    default upgrade;
    ''      close;
}}

server {{
    listen 80;
    listen [::]:80;
    server_name {DOMAIN};
    return 301 https://$host$request_uri;
}}

server {{
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name {DOMAIN};

    ssl_certificate /etc/letsencrypt/live/{DOMAIN}/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/{DOMAIN}/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;

    client_max_body_size 64M;

    location / {{
        proxy_pass https://{NEW_IP};
        proxy_http_version 1.1;
        proxy_ssl_server_name on;
        proxy_ssl_name {DOMAIN};
        proxy_ssl_verify off;
        proxy_set_header Host {DOMAIN};
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-Forwarded-Host {DOMAIN};
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection $connection_upgrade;
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
        proxy_buffering off;
    }}
}}
EOF

rm -f /etc/nginx/sites-enabled/apexone /etc/nginx/sites-enabled/apexone-closed /etc/nginx/sites-enabled/default
ln -sfn /etc/nginx/sites-available/apexone-proxy /etc/nginx/sites-enabled/apexone-proxy
nginx -t
systemctl reload nginx

curl -sk -o /dev/null -w 'old_proxy_admin=%{{http_code}}\\n' -H 'Host: {DOMAIN}' https://127.0.0.1/admin/login
curl -sk -o /dev/null -w 'old_proxy_portal=%{{http_code}}\\n' -H 'Host: {DOMAIN}' https://127.0.0.1/portal/login
curl -sk https://127.0.0.1/admin/login -H 'Host: {DOMAIN}' | grep -oE 'Admin sign in|Agent sign in|ApexOne Command Center|Sign in|username' | sort -u | head
echo OLD_PROXY_OK
""",
        )
    )
    old.close()

    print("=== Done ===")
    print(f"Live URL still DNS→{OLD['host']}, but traffic is now proxied to {NEW_IP}.")
    print(f"Change DNS A record for {DOMAIN} → {NEW_IP} when ready (then remove old proxy).")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
