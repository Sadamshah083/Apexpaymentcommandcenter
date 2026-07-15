#!/usr/bin/env python3
"""Deploy double-click disposition → save & close."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

FILES = [
    "resources/js/communications-auto-dial.js",
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
cd {REMOTE_APP} && npm run build > /tmp/vite-disposition-dblclick.log 2>&1
tail -n 16 /tmp/vite-disposition-dblclick.log
echo BUILD:$?
chown -R www-data:www-data {REMOTE_APP}/public/build
cd {REMOTE_APP} && sudo -u www-data php artisan view:clear
grep -n "dblclick\\|selectDispositionChip\\|Double-click" \\
  {REMOTE_APP}/resources/js/communications-auto-dial.js \\
  {REMOTE_APP}/resources/views/communications/partials/call-summary-modal.blade.php | head -30
""",
            check=False,
        )
    )
    ssh.close()
    print("Double-click disposition deployed. Ctrl+F5 dialer.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
