#!/usr/bin/env python3
"""Deploy: clear dial pad after disposition / while ringing; instant Next close on Call Summary."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

FILES = [
    "resources/js/communications-dialer.js",
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
cd {REMOTE_APP} && npm run build > /tmp/vite-disposition-next.log 2>&1
tail -n 16 /tmp/vite-disposition-next.log
echo BUILD:$?
chown -R www-data:www-data {REMOTE_APP}/public/build
grep -n "clearDialerDestinationInput\\|dispositionBusy\\|Instant close\\|Saving…" \\
  {REMOTE_APP}/resources/js/communications-auto-dial.js \\
  {REMOTE_APP}/resources/js/communications-dialer.js | head -30
""",
            check=False,
        )
    )
    ssh.close()
    print("Disposition Next + clear-number fix deployed. Ctrl+F5.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
