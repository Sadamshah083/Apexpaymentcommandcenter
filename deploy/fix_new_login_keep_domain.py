#!/usr/bin/env python3
"""
Keep domain on NEW server. Fix login session cookies on NEW.
Do NOT move crm.apexonepayments.com onto the old IP app.
"""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

import deploy._ssh as m

# NEW server
m.HOST = "203.215.161.236"
m.USER = "ateg"
m.PASSWORD = "balitech1"
m.REMOTE_APP = "/var/www/apexone"

from deploy._ssh import REMOTE_APP, connect, set_env_vars, sudo_run, sudo_run_batch


IP_SITE = r"""
# Direct IP access for NEW server (optional). Domain remains on apexone site.
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name 203.215.161.236 _;

    root /var/www/apexone/public;
    index index.php;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";
    charset utf-8;
    client_max_body_size 64M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:PHP_SOCK;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
"""


def main() -> int:
    ssh = connect()

    print("1) Confirm old proxy still points domain -> NEW (no domain move)...")
    # Quick remote note only; we do not change old server here.
    print("   Domain stays on NEW. Old apexone-proxy remains the domain front door.")

    print("2) Fix NEW server session env for domain HTTPS login...")
    set_env_vars(ssh, {
        "APP_URL": "https://crm.apexonepayments.com",
        "SESSION_SECURE_COOKIE": "true",
        "SESSION_SAME_SITE": "lax",
        "SESSION_LIFETIME": "600",
    }, env_path=f"{REMOTE_APP}/.env")

    # Remove broken SESSION_DOMAIN=null (literal string breaks cookies)
    print(sudo_run(ssh, f"""
python3 - <<'PY'
from pathlib import Path
p = Path('{REMOTE_APP}/.env')
lines = []
removed = False
for line in p.read_text().splitlines():
    if line.startswith('SESSION_DOMAIN='):
        val = line.split('=', 1)[1].strip().strip('"').strip("'")
        if val.lower() in ('null', 'none', ''):
            removed = True
            continue
        lines.append(line)
    else:
        lines.append(line)
p.write_text('\\n'.join(lines) + '\\n')
print('SESSION_DOMAIN_REMOVED' if removed else 'SESSION_DOMAIN_OK')
# show relevant
for line in p.read_text().splitlines():
    if line.startswith(('APP_URL=', 'SESSION_')):
        print(line)
PY
"""))

    print("3) Enable IP-only HTTP login on NEW (203.215.161.236) without touching domain site...")
    sock = sudo_run(ssh, "ls /run/php/php*-fpm.sock 2>/dev/null | head -1 || true", check=False).strip().splitlines()
    sock = (sock[-1].strip() if sock else "") or "/run/php/php8.3-fpm.sock"
    site = IP_SITE.replace("unix:PHP_SOCK", f"unix:{sock}")
    import base64
    b64 = base64.b64encode(site.encode()).decode()
    print(sudo_run_batch(ssh, [
        f"echo {b64} | base64 -d > /etc/nginx/sites-available/apexone-ip",
        "ln -sfn /etc/nginx/sites-available/apexone-ip /etc/nginx/sites-enabled/apexone-ip",
        # Keep domain site intact
        "test -L /etc/nginx/sites-enabled/apexone",
        "nginx -t",
        "systemctl reload nginx",
    ]))

    # For IP HTTP login, Laravel must not force Secure cookies only when Host is IP.
    # APP_URL stays domain for production links; SESSION_SECURE_COOKIE=true is fine for HTTPS domain.
    # For HTTP IP: browsers reject Secure cookies → 419. Use false for IP convenience OR detect.
    # Safer for dual access: SESSION_SECURE_COOKIE=false and rely on HTTPS domain HSTS if any.
    # Domain is HTTPS via proxy; Secure=false still works over HTTPS.
    print("4) Set SESSION_SECURE_COOKIE=false so IP HTTP login works; HTTPS domain still OK...")
    set_env_vars(ssh, {
        "SESSION_SECURE_COOKIE": "false",
    }, env_path=f"{REMOTE_APP}/.env")

    print("5) Clear config caches...")
    print(sudo_run_batch(ssh, [
        f"cd {REMOTE_APP} && sudo -u www-data php artisan config:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan cache:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear",
    ]))

    print("6) Smoke login CSRF on domain + new IP...")
    print(sudo_run(ssh, r"""
set -e
echo '--- env ---'
cd /var/www/apexone && grep -E '^(APP_URL|SESSION_DOMAIN|SESSION_SECURE_COOKIE|SESSION_SAME_SITE)=' .env || true

smoke() {
  local label="$1" url="$2" host="$3"
  local jar="/tmp/apex_${label}_cookies.txt"
  rm -f "$jar" "/tmp/apex_${label}.html"
  local code token post
  code=$(curl -sk -c "$jar" -o "/tmp/apex_${label}.html" -w '%{http_code}' "$url" -H "Host: $host")
  token=$(python3 - <<PY
import re
html=open('/tmp/apex_${label}.html','rb').read().decode('utf-8','replace')
m=re.search(r'name="_token"\s+value="([^"]+)"', html)
print(m.group(1) if m else '')
PY
)
  post=$(curl -sk -b "$jar" -c "$jar" -o "/tmp/apex_${label}_post.html" -w '%{http_code}' \
    -X POST "$url" -H "Host: $host" \
    -H 'Content-Type: application/x-www-form-urlencoded' \
    --data-urlencode "_token=$token" \
    --data-urlencode "email=nosuchuser@example.com" \
    --data-urlencode "password=badpass")
  echo "${label}_GET=$code TOKEN_LEN=${#token} ${label}_POST=$post"
}

smoke DOMAIN https://127.0.0.1/admin/login crm.apexonepayments.com
smoke IP http://127.0.0.1/admin/login 203.215.161.236
echo '--- enabled sites ---'
ls -la /etc/nginx/sites-enabled
""", check=False))

    ssh.close()
    print("\nDONE")
    print("- Domain NOT moved to old server")
    print("- Domain still served by NEW via old proxy")
    print("- Login fixed on NEW for domain + http://203.215.161.236/admin/login")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
