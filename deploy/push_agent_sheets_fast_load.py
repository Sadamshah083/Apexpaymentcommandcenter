#!/usr/bin/env python3
"""Deploy: agent uploaded sheets visible + faster CRM load."""

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
    "app/Services/Communications/DialerImportedLeadsService.php",
    "app/Services/Communications/CommunicationsInboxService.php",
    "app/Http/Controllers/CommunicationsHubController.php",
    "resources/js/communications-auto-dial.js",
    "resources/views/communications/partials/center-dialer-hub.blade.php",
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
npm run build > /tmp/vite-agent-sheets.log 2>&1
tail -n 14 /tmp/vite-agent-sheets.log
echo BUILD:$?
chown -R www-data:www-data {REMOTE_APP}/public/build
php artisan view:clear >/dev/null 2>&1 || true
php artisan optimize:clear >/dev/null 2>&1 || true
grep -n "fast distinct-sheet\\|My lead sheets\\|syncFiltersSizeUi(true)\\|hydrateDialerLeadFilters\\|Agent sheets load" \\
  app/Services/Communications/DialerImportedLeadsService.php \\
  app/Services/Communications/CommunicationsInboxService.php \\
  resources/js/communications-auto-dial.js \\
  resources/views/communications/partials/center-dialer-hub.blade.php | head -25
""",
            check=False,
        )
        print(out)
    finally:
        ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
