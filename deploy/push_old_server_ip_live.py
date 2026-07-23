#!/usr/bin/env python3
"""
Bring Laravel app live on OLD server via IP only.

- Deploy latest code to 203.215.160.44
- Serve app at http://203.215.160.44 (NO domain on this vhost)
- Keep apexone-proxy (domain -> new server) unchanged
- Do not change nginx on the new server
"""

from __future__ import annotations

import os
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

import deploy._ssh as m

m.HOST = "203.215.160.44"
m.USER = "issac"
m.PASSWORD = "SadamShah123"
m.REMOTE_APP = "/var/www/apexone"

from deploy._ssh import REMOTE_APP, connect, set_env_vars, sudo_run, sudo_run_batch, upload_files

# Recent live features + core assets needed to match new server UX
FILES = [
    "app/Support/UsAreaCodeState.php",
    "app/Jobs/ProcessWorkflowJob.php",
    "app/Services/Workflow/WorkflowDashboardService.php",
    "app/Http/Controllers/AdminDashboardController.php",
    "app/Http/Controllers/WorkflowController.php",
    "app/Http/Controllers/CommunicationsHubController.php",
    "app/Services/Communications/DialerImportedLeadsService.php",
    "app/Services/Communications/CommunicationsInboxService.php",
    "config/integrations.php",
    "resources/views/communications/partials/call-summary-modal.blade.php",
    "resources/views/admin/dashboard/partials/imports-panel.blade.php",
    "resources/views/communications/partials/center-dialer-hub.blade.php",
    "resources/views/communications/partials/dialer-lead-row.blade.php",
    "resources/views/auth/login_portal.blade.php",
    "resources/views/auth/login_admin.blade.php",
    "resources/views/layouts/partials/sidebar-shell.blade.php",
    "resources/views/components/pagination.blade.php",
    "resources/views/workflows/partials/import-modals.blade.php",
    "resources/views/pipeline/partials/assign-leads-modal.blade.php",
    "resources/js/communications-auto-dial.js",
    "resources/js/workspace-sync.js",
    "resources/js/pretty-select.js",
    "resources/js/app.js",
    "resources/css/app.css",
    "resources/css/comm-hub-ui-polish.css",
    "resources/css/comm-hub-ghl-theme.css",
]

IP_SITE = r"""
# IP-only Laravel app on old server. Domain stays on apexone-proxy -> new server.
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name 203.215.160.44 _;

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
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
"""


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES]
    missing = [str(p) for p, _ in pairs if not p.is_file()]
    if missing:
        print("Missing files:")
        for path in missing:
            print(f"  - {path}")
        return 1

    ssh = connect()
    print("1) Uploading latest code to OLD server...")
    upload_files(ssh, pairs, app_root=REMOTE_APP)

    print("2) Setting APP_URL to old server IP (not domain)...")
    set_env_vars(ssh, {
        "APP_URL": "http://203.215.160.44",
        "SESSION_LIFETIME": "600",
        "SESSION_DOMAIN": "null",
    }, env_path=f"{REMOTE_APP}/.env")

    print("3) Enabling IP-only nginx site (keeping domain proxy untouched)...")
    # Detect php-fpm socket
    sock_probe = sudo_run(ssh, "ls /run/php/php*-fpm.sock 2>/dev/null | head -1 || true", check=False).strip()
    sock = sock_probe.splitlines()[-1].strip() if sock_probe else "/run/php/php8.3-fpm.sock"
    site = IP_SITE.replace("unix:/run/php/php8.3-fpm.sock", f"unix:{sock}")

    # Write site via base64 to avoid quoting issues
    import base64
    b64 = base64.b64encode(site.encode()).decode()
    print(sudo_run_batch(ssh, [
        f"echo {b64} | base64 -d > /etc/nginx/sites-available/apexone-ip",
        "ln -sfn /etc/nginx/sites-available/apexone-ip /etc/nginx/sites-enabled/apexone-ip",
        # Ensure domain proxy site remains enabled and is the ONLY domain listener
        "test -L /etc/nginx/sites-enabled/apexone-proxy || ln -sfn /etc/nginx/sites-available/apexone-proxy /etc/nginx/sites-enabled/apexone-proxy",
        # Make sure the full domain-named local app is NOT enabled (domain must not bind to local app)
        "rm -f /etc/nginx/sites-enabled/apexone",
        "rm -f /etc/nginx/sites-enabled/default",
        "nginx -t",
        "systemctl reload nginx",
    ]))

    print("4) Build assets + clear caches on OLD server...")
    print(sudo_run_batch(ssh, [
        f"cd {REMOTE_APP} && npm run build > /tmp/vite-old-ip.log 2>&1; echo BUILD:$?; tail -n 20 /tmp/vite-old-ip.log",
        f"chown -R www-data:www-data {REMOTE_APP}/public/build",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan config:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan cache:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan route:clear",
    ]))

    print("5) Smoke checks...")
    print(sudo_run_batch(ssh, [
        "ls -la /etc/nginx/sites-enabled",
        "grep -n 'server_name\\|proxy_pass\\|listen' /etc/nginx/sites-enabled/apexone-ip /etc/nginx/sites-enabled/apexone-proxy | head -40",
        f"cd {REMOTE_APP} && grep -E '^(APP_URL|SESSION_LIFETIME)=' .env",
        "curl -s -o /dev/null -w 'IP_HTTP=%{http_code}\\n' -H 'Host: 203.215.160.44' http://127.0.0.1/admin/login || true",
        "curl -s -o /dev/null -w 'DOMAIN_PROXY=%{http_code}\\n' -H 'Host: crm.apexonepayments.com' -k https://127.0.0.1/admin/login || true",
    ], check=False))

    ssh.close()
    print("\nOLD server IP app is live at http://203.215.160.44")
    print("Domain proxy unchanged (still forwards to new server).")
    print("New server was not modified.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
