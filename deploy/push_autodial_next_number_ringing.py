#!/usr/bin/env python3
"""Deploy: after disposition dial next number + never close ringing UI mid-ring."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

FILES = [
    "resources/js/communications-auto-dial.js",
    "resources/js/communications-webphone.js",
    "resources/css/comm-hub-ghl-theme.css",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    upload_files(ssh, pairs, REMOTE_APP)
    print(
        sudo_run(
            ssh,
            f"""
cd {REMOTE_APP} && npm run build > /tmp/vite-autodial-next-ring.log 2>&1
tail -n 20 /tmp/vite-autodial-next-ring.log
echo BUILD:$?
chown -R www-data:www-data {REMOTE_APP}/public/build
grep -n "disposedLeadIds\\|lastDisposedPhone\\|isOutboundRingingUiActive\\|ch-outbound-ringing" \\
  {REMOTE_APP}/resources/js/communications-auto-dial.js \\
  {REMOTE_APP}/resources/js/communications-webphone.js \\
  {REMOTE_APP}/resources/css/comm-hub-ghl-theme.css | head -40
""",
            check=False,
        )
    )
    ssh.close()
    print("Auto-dial next-number + ringing UI fix deployed. Hard refresh (Ctrl+F5) dialer.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
