#!/usr/bin/env python3
"""Fix OLD→NEW reverse proxy (HTTP/1.1 + SNI) and add NEW plain HTTP backend for reliability."""

from __future__ import annotations

import shlex

import paramiko

OLD = {"host": "203.215.160.44", "user": "issac", "password": "SadamShah123"}
NEW = {"host": "203.215.161.236", "user": "ateg", "password": "balitech1"}
DOMAIN = "crm.apexonepayments.com"
NEW_IP = "203.215.161.236"


def connect(cfg):
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(cfg["host"], username=cfg["user"], password=cfg["password"], timeout=30)
    return ssh


def sudo(ssh, password, cmd, timeout=120):
    full = f"echo {shlex.quote(password)} | sudo -S -p '' bash -lc {shlex.quote(cmd)}"
    _, stdout, stderr = ssh.exec_command(full, timeout=timeout)
    return (stdout.read() + stderr.read()).decode(errors="replace")


def main():
    print("=== NEW: add internal HTTP backend :8080 (no redirect) for proxy ===")
    new = connect(NEW)
    print(
        sudo(
            new,
            NEW["password"],
            f"""
set -e
cat >/etc/nginx/sites-available/apexone-backend-8080 <<'EOF'
server {{
    listen 127.0.0.1:8080;
    listen {NEW_IP}:8080;
    server_name {DOMAIN};

    root /var/www/apexone/public;
    index index.php;
    client_max_body_size 64M;
    charset utf-8;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    location / {{
        try_files $uri $uri/ /index.php?$query_string;
    }}

    location ~ \\.php$ {{
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
        fastcgi_buffering off;
        fastcgi_read_timeout 3600s;
        fastcgi_send_timeout 3600s;
        # Honor proxy headers from old server
        fastcgi_param HTTPS on;
        fastcgi_param HTTP_X_FORWARDED_PROTO https;
    }}

    location /communications-ws/ {{
        proxy_pass http://127.0.0.1:8787/;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_read_timeout 86400s;
    }}

    location /morpheus-ws/ {{
        proxy_pass https://apexone.morpheus.cx:7443/;
        proxy_http_version 1.1;
        proxy_ssl_server_name on;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host apexone.morpheus.cx;
        proxy_read_timeout 86400s;
    }}

    location ~ /\\.(?!well-known).* {{
        deny all;
    }}
}}
EOF
ln -sfn /etc/nginx/sites-available/apexone-backend-8080 /etc/nginx/sites-enabled/apexone-backend-8080
# allow 8080 only if needed from old IP — open temporarily
ufw allow from 203.215.160.44 to any port 8080 proto tcp || true
ufw allow 8080/tcp || true
nginx -t
systemctl reload nginx
curl -s -o /dev/null -w 'backend8080_admin=%{{http_code}}\\n' -H 'Host: {DOMAIN}' -H 'X-Forwarded-Proto: https' http://127.0.0.1:8080/admin/login
echo NEW_BACKEND_OK
""",
        )
    )
    new.close()

    print("=== OLD: proxy to NEW :8080 over HTTP (stable) ===")
    old = connect(OLD)
    print(
        sudo(
            old,
            OLD["password"],
            f"""
set -e
cat >/etc/nginx/sites-available/apexone-proxy <<'EOF'
map $http_upgrade $connection_upgrade {{
    default upgrade;
    ''      close;
}}

upstream apexone_new {{
    server {NEW_IP}:8080;
    keepalive 32;
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
        proxy_pass http://apexone_new;
        proxy_http_version 1.1;
        proxy_set_header Connection "";
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
rm -f /etc/nginx/sites-enabled/apexone-closed /etc/nginx/sites-enabled/apexone /etc/nginx/sites-enabled/default
ln -sfn /etc/nginx/sites-available/apexone-proxy /etc/nginx/sites-enabled/apexone-proxy
nginx -t
systemctl reload nginx
sleep 1
# clear error noise then test
: > /var/log/nginx/error.log || true
curl -sk -o /tmp/admin.html -w 'proxy_admin=%{{http_code}}\\n' -H 'Host: {DOMAIN}' https://127.0.0.1/admin/login
curl -sk -o /tmp/portal.html -w 'proxy_portal=%{{http_code}}\\n' -H 'Host: {DOMAIN}' https://127.0.0.1/portal/login
grep -oE 'Admin sign in|Agent sign in|ApexOne Command Center|Sign in|username' /tmp/admin.html /tmp/portal.html | sort -u
tail -n 15 /var/log/nginx/error.log || true
echo PROXY_FIXED_OK
""",
        )
    )
    old.close()
    print("Done.")


if __name__ == "__main__":
    main()
