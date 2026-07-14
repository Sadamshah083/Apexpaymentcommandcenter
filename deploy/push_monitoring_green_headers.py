#!/usr/bin/env python3
from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

FILES = [
    "resources/css/app.css",
    "resources/js/call-monitoring.js",
    "resources/views/communications/monitoring/partials/row.blade.php",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES]
    ssh = connect()
    upload_files(ssh, pairs, REMOTE_APP)
    print(
        sudo_run(
            ssh,
            f"""
cd {REMOTE_APP} && npm run build > /tmp/vite-green-hdr.log 2>&1
tail -n 10 /tmp/vite-green-hdr.log
echo BUILD:$?
chown -R www-data:www-data {REMOTE_APP}/public/build
cd {REMOTE_APP} && sudo -u www-data php artisan view:clear
grep -n "background: #166534\\|call-monitoring-dial-pill\\|destinationCellHtml" \\
  {REMOTE_APP}/resources/css/app.css \\
  {REMOTE_APP}/resources/js/call-monitoring.js | head -20
""",
            check=False,
        )
    )
    ssh.close()
    print("Green headers + dial mode pills deployed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
