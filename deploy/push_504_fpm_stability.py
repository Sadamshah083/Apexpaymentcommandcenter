#!/usr/bin/env python3
"""Deploy 504 / FPM / polling / SSE / index fixes. Reloads php-fpm to free stuck workers."""
from __future__ import annotations

import sys
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]
NEW = {"host": "203.215.161.236", "user": "ateg", "password": "balitech1"}
REMOTE = "/var/www/apexone"
FILES = [
    "app/Http/Controllers/CallMonitoringController.php",
    "app/Http/Controllers/MorpheusHubController.php",
    "app/Http/Controllers/WorkspaceSyncController.php",
    "resources/js/call-monitoring.js",
    "resources/js/workspace-sync.js",
    "resources/js/portal-dashboard.js",
    "resources/views/admin/dashboard/index.blade.php",
    "resources/views/maps-scraper/index.blade.php",
    "database/migrations/2026_07_23_221500_add_disposition_performance_indexes.php",
    "deploy/nginx-apexone.conf",
]


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(NEW["host"], username=NEW["user"], password=NEW["password"], timeout=40)

    sys.path.insert(0, str(ROOT))
    import deploy._ssh as ssh_mod
    from deploy._ssh import sudo_run, upload_files

    ssh_mod.HOST = NEW["host"]
    ssh_mod.USER = NEW["user"]
    ssh_mod.PASSWORD = NEW["password"]
    ssh_mod.REMOTE_APP = REMOTE

    print(f"Uploading {len(FILES)} files...")
    upload_files(ssh, [(ROOT / rel, rel) for rel in FILES], app_root=REMOTE)

    # Update checked-in nginx template on disk for reference; live site gets SSE location + FPM pool.
    inner = f"""
set -e
cd {REMOTE}
chown -R www-data:www-data \\
  app/Http/Controllers/CallMonitoringController.php \\
  app/Http/Controllers/MorpheusHubController.php \\
  app/Http/Controllers/WorkspaceSyncController.php \\
  resources/js/call-monitoring.js \\
  resources/js/workspace-sync.js \\
  resources/js/portal-dashboard.js \\
  resources/views/admin/dashboard/index.blade.php \\
  resources/views/maps-scraper/index.blade.php \\
  database/migrations/2026_07_23_221500_add_disposition_performance_indexes.php

# --- PHP-FPM: 5 workers caused site-wide 504 when SSE pinned them ---
POOL=/etc/php/8.3/fpm/pool.d/www.conf
cp -a "$POOL" "$POOL.bak.504fix.$(date +%s)"
sed -i 's/^pm.max_children = .*/pm.max_children = 25/' "$POOL"
sed -i 's/^pm.start_servers = .*/pm.start_servers = 4/' "$POOL"
sed -i 's/^pm.min_spare_servers = .*/pm.min_spare_servers = 2/' "$POOL"
sed -i 's/^pm.max_spare_servers = .*/pm.max_spare_servers = 8/' "$POOL"
if grep -q '^request_terminate_timeout' "$POOL"; then
  sed -i 's/^request_terminate_timeout = .*/request_terminate_timeout = 180s/' "$POOL"
else
  echo 'request_terminate_timeout = 180s' >> "$POOL"
fi
grep -E '^(pm\\.max_children|pm\\.start_servers|pm\\.min_spare|pm\\.max_spare|request_terminate)' "$POOL"

# --- Nginx: keep API timeouts moderate; long only for WS ---
SITE=/etc/nginx/sites-available/apexone
if [ -f "$SITE" ]; then
  cp -a "$SITE" "$SITE.bak.504fix.$(date +%s)"
fi
# Ensure communications-ws / morpheus-ws already have long timeouts (verified earlier).
# Cap PHP streams via app code; keep fastcgi_read_timeout 120s for normal APIs.
nginx -t
systemctl reload nginx

# Free stuck SSE workers immediately
systemctl reload php8.3-fpm
sleep 2
ps -eo pid,etime,pcpu,cmd | awk '/php-fpm: pool www/ {{print}}' | head -20

php artisan migrate --force --no-interaction
php artisan view:clear
npm run build --silent
ls -1 public/build/assets/communications-*.js | tail -2
grep -n "maxSeconds = 45\\|Prefer HTTP poll over PHP SSE\\|SYNC_FULL_POLL_MS = 30000\\|pm.max_children = 25" \\
  app/Http/Controllers/CallMonitoringController.php \\
  app/Http/Controllers/MorpheusHubController.php \\
  resources/js/call-monitoring.js \\
  resources/js/workspace-sync.js \\
  /etc/php/8.3/fpm/pool.d/www.conf | head -30
echo DONE_504_FPM_FIX
"""
    out = sudo_run(ssh, inner)
    print(out.encode("ascii", "replace").decode("ascii"))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
