#!/usr/bin/env python3
"""Deploy centered colorful call-summary dispositions."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

FILES = [
    "resources/views/communications/partials/call-summary-modal.blade.php",
    "resources/css/comm-hub-ui-polish.css",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES]
    ssh = connect()
    upload_files(ssh, pairs, REMOTE_APP)
    print(
        sudo_run(
            ssh,
            f"""
cd {REMOTE_APP} && npm run build > /tmp/vite-dispo-center.log 2>&1
tail -n 16 /tmp/vite-dispo-center.log
echo BUILD:$?
chown -R www-data:www-data {REMOTE_APP}/public/build
php artisan view:clear >/dev/null 2>&1 || true
grep -n "dispo-btn--\\|align-items: center\\|justify-content: center" \\
  {REMOTE_APP}/resources/css/comm-hub-ui-polish.css | head -20
grep -n "dispositionTones\\|dispo-btn--" \\
  {REMOTE_APP}/resources/views/communications/partials/call-summary-modal.blade.php | head -15
""",
            check=False,
        )
    )
    ssh.close()
    print("Centered colorful dispositions deployed. Ctrl+F5 dialer.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
