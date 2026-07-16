#!/usr/bin/env python3
"""Deploy disposition double-click save + call-log disposition display."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

FILES = [
    "resources/js/communications-auto-dial.js",
    "resources/js/communications-dialer.js",
    "resources/css/comm-hub-ui-polish.css",
    "resources/views/communications/partials/call-summary-modal.blade.php",
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
npm run build > /tmp/vite-dispo-dblclick.log 2>&1
tail -n 16 /tmp/vite-dispo-dblclick.log
echo BUILD:$?
chown -R www-data:www-data {REMOTE_APP}/public/build
grep -n "dispositionChipTap\\|dispositionOverride\\|double-tap\\|Never fall back disposition" \\
  {REMOTE_APP}/resources/js/communications-auto-dial.js | head -25
grep -n "Double-click / double-tap\\|ghl-dialer-recent-field--disposition" \\
  {REMOTE_APP}/resources/views/communications/partials/call-summary-modal.blade.php \\
  {REMOTE_APP}/resources/css/comm-hub-ui-polish.css | head -15
""",
            check=False,
        )
    )
    ssh.close()
    print("Disposition double-click + call-log display deployed. Ctrl+F5.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
