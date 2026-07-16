#!/usr/bin/env python3
"""Deploy optimized disposition API + 6s next auto-dial."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

FILES = [
    "app/Http/Controllers/CommunicationsHubController.php",
    "resources/js/communications-auto-dial.js",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    upload_files(ssh, pairs, REMOTE_APP)
    print(
        sudo_run(
            ssh,
            f"""
cd {REMOTE_APP}
php artisan view:clear >/dev/null 2>&1 || true
php artisan config:clear >/dev/null 2>&1 || true
npm run build > /tmp/vite-disposition-opt.log 2>&1
tail -n 14 /tmp/vite-disposition-opt.log
echo BUILD:$?
chown -R www-data:www-data {REMOTE_APP}/public/build
grep -n "next_call_delay_sec\\|ReleaseSessionLock\\|Next call in 6s\\|AUTO_DIAL_DELAY_MS = 6000" \\
  {REMOTE_APP}/app/Http/Controllers/CommunicationsHubController.php \\
  {REMOTE_APP}/resources/js/communications-auto-dial.js | head -25
""",
            check=False,
        )
    )
    ssh.close()
    print("Disposition optimized + 6s next dial deployed. Hard refresh (Ctrl+F5).")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
