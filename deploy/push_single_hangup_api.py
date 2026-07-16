#!/usr/bin/env python3
"""Deploy single hangup API (no duplicate /ended) + non-blocking next dial."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

FILES = [
    "resources/js/communications-webphone.js",
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
npm run build > /tmp/vite-single-hangup.log 2>&1
tail -n 16 /tmp/vite-single-hangup.log
echo BUILD:$?
chown -R www-data:www-data {REMOTE_APP}/public/build
grep -n "http: false\\|Single hangup\\|Fire-and-forget Morpheus hangup\\|Do NOT POST /ended" \\
  {REMOTE_APP}/resources/js/communications-webphone.js | head -20
grep -n "killDestinationLegsNow\\|notifyMonitoringHangup" \\
  {REMOTE_APP}/public/build/assets/communications*.js | head -5 || true
""",
            check=False,
        )
    )
    ssh.close()
    print("Single hangup API deployed. Hard refresh (Ctrl+F5).")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
