#!/usr/bin/env python3
"""Deploy single destination-connected + single call-events WebSocket."""

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
npm run build > /tmp/vite-dedupe-connect.log 2>&1
tail -n 16 /tmp/vite-dedupe-connect.log
echo BUILD:$?
chown -R www-data:www-data {REMOTE_APP}/public/build
grep -n "Exactly ONE POST\\|One socket per call\\|agent-sync\\|skip stop/restart" \\
  {REMOTE_APP}/resources/js/communications-webphone.js | head -20
""",
            check=False,
        )
    )
    ssh.close()
    print("Deduped destination-connected + websocket. Hard refresh (Ctrl+F5).")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
