#!/usr/bin/env python3
"""Deploy fix for sticky 'Set disposition to continue' status."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

FILES = [
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
cd {REMOTE_APP} && npm run build > /tmp/vite-disposition-status.log 2>&1
tail -n 14 /tmp/vite-disposition-status.log
echo BUILD:$?
chown -R www-data:www-data {REMOTE_APP}/public/build
grep -n "isSummaryModalVisible\\|Saving disposition\\|Recover sticky\\|Always refresh status" \\
  {REMOTE_APP}/resources/js/communications-auto-dial.js | head -30
""",
            check=False,
        )
    )
    ssh.close()
    print("Disposition status fix deployed. Ctrl+F5 dialer.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
