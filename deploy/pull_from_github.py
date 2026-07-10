#!/usr/bin/env python3
"""Link production app to GitHub repo and pull latest main."""
from __future__ import annotations

import os
import shlex
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "deploy"))

from _ssh import REMOTE_APP, connect, sudo_run  # noqa: E402

REPO = os.environ.get(
    "GITHUB_REPO",
    "https://github.com/Sadamshah083/Apexpaymentcommandcenter.git",
)


def main() -> int:
    password = os.environ.get("DEPLOY_PASSWORD", "")
    if not password:
        print("Set DEPLOY_PASSWORD", file=sys.stderr)
        return 1

    ssh = connect()
    script = f"""
set -euo pipefail
APP={shlex.quote(REMOTE_APP)}
REPO={shlex.quote(REPO)}
cd "$APP"
git config --global --add safe.directory "$APP" || true
test -f .env && cp .env /tmp/apexone.env.bak || true
if [ ! -d .git ]; then
  git init
  git remote add origin "$REPO"
else
  git remote set-url origin "$REPO" 2>/dev/null || git remote add origin "$REPO"
fi
git fetch origin main
git reset --hard origin/main
test -f /tmp/apexone.env.bak && cp /tmp/apexone.env.bak .env || true
export COMPOSER_ALLOW_SUPERUSER=1
composer install --no-dev --optimize-autoloader --no-interaction
npm ci --ignore-scripts
npm run build
rm -rf node_modules
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
rm -f public/hot
chown -R www-data:www-data storage bootstrap/cache public/build .env
systemctl restart apexone-queue php8.3-fpm
systemctl reload nginx
curl -fsS https://crm.apexonepayments.com/up
"""
    out = sudo_run(ssh, script)
    print(out.encode("ascii", errors="replace").decode("ascii"))
    ssh.close()
    print("DEPLOY FROM GITHUB COMPLETE")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
