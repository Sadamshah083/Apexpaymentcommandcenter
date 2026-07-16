#!/usr/bin/env python3
"""Deploy fast communications document first-paint."""

from __future__ import annotations

import os
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

os.environ.setdefault("DEPLOY_PASSWORD", "SadamShah123")
import deploy._ssh as ssh_mod

ssh_mod.PASSWORD = os.environ.get("DEPLOY_PASSWORD", "") or ssh_mod.PASSWORD
from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

FILES = [
    "app/Services/Communications/CommunicationsInboxService.php",
    "resources/views/communications/partials/center-dialer-hub.blade.php",
    "resources/js/communications-dialer.js",
]

REMOTE = r"""
cd /var/www/apexone
npm run build > /tmp/vite-comm-fast.log 2>&1
echo BUILD:$?
tail -n 8 /tmp/vite-comm-fast.log
php artisan view:clear >/dev/null 2>&1 || true
php artisan cache:clear >/dev/null 2>&1 || true
php artisan config:clear >/dev/null 2>&1 || true
chown -R www-data:www-data /var/www/apexone/public/build
grep -c 'Fast first paint' app/Services/Communications/CommunicationsInboxService.php
grep -c 'hydrate the first page' resources/js/communications-dialer.js
"""


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    upload_files(ssh, pairs, REMOTE_APP)
    print(sudo_run(ssh, REMOTE, check=False).encode("ascii", "replace").decode("ascii"))
    ssh.close()
    print("Fast communications paint deployed. Hard refresh.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
