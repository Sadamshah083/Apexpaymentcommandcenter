#!/usr/bin/env python3
"""Deploy project-wide CRM load optimizations (presence split, fonts, nginx cache)."""

from __future__ import annotations

import os
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
os.environ.setdefault("DEPLOY_PASSWORD", "balitech1")

import deploy._ssh as m

m.HOST = "203.215.161.236"
m.USER = "ateg"
m.PASSWORD = "balitech1"
m.REMOTE_APP = "/var/www/apexone"

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

FILES = [
    "resources/js/app.js",
    "resources/js/agent-presence.js",
    "vite.config.js",
    "resources/views/layouts/partials/vite-assets.blade.php",
    "app/Http/Middleware/MarketerPortalMiddleware.php",
    "deploy/nginx-apexone.conf",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    missing = [rel for rel in FILES if not (ROOT / rel).is_file()]
    if missing:
        print("Missing:")
        for path in missing:
            print(f"  - {path}")
        return 1

    ssh = connect()
    try:
        upload_files(ssh, pairs, REMOTE_APP)
        out = sudo_run(
            ssh,
            f"""
cd {REMOTE_APP}
npm run build > /tmp/vite-global-opt.log 2>&1
tail -n 20 /tmp/vite-global-opt.log
echo BUILD:$?
chown -R www-data:www-data {REMOTE_APP}/public/build
cp {REMOTE_APP}/deploy/nginx-apexone.conf /etc/nginx/sites-available/apexone
nginx -t
systemctl reload nginx
systemctl reload php8.3-fpm 2>/dev/null || true
php artisan view:clear >/dev/null 2>&1 || true
php artisan optimize:clear >/dev/null 2>&1 || true
ls -1 public/build/assets | grep -E 'agent-presence|communications-|app-|fonts-' | head -20
grep -n "agent-presence\\|initAgentPresenceLite\\|gzip on\\|location ^~ /build" \\
  resources/js/app.js deploy/nginx-apexone.conf | head -20
""",
            check=False,
        )
        print(out)
    finally:
        ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
