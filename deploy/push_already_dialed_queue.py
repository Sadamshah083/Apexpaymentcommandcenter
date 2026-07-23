#!/usr/bin/env python3
"""Deploy: already-dialed numbers leave admin + agent imported-leads queues."""

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
    "app/Http/Controllers/CommunicationsHubController.php",
    "resources/js/communications-auto-dial.js",
    "tests/Feature/DialerDispositionTest.php",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    try:
        upload_files(ssh, pairs, REMOTE_APP)
        out = sudo_run(
            ssh,
            f"""
cd {REMOTE_APP}
npm run build > /tmp/vite-already-dialed.log 2>&1
tail -n 12 /tmp/vite-already-dialed.log
echo BUILD:$?
chown -R www-data:www-data {REMOTE_APP}/public/build
php artisan view:clear >/dev/null 2>&1 || true
php artisan optimize:clear >/dev/null 2>&1 || true
grep -n "function markDialed\\|Already dialed / dispositioned\\|Always drop this number" \\
  app/Services/Communications/DialerImportedLeadsService.php \\
  resources/js/communications-auto-dial.js | head -20
""",
            check=False,
        )
        sys.stdout.buffer.write((out or "").encode("utf-8", errors="replace") + b"\n")
    finally:
        ssh.close()
    print("Already-dialed queue fix deployed. Ctrl+F5 admin + agent dialer.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
