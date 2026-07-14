#!/usr/bin/env python3
"""Deploy: single disposition popup + keep ringing UI during outbound dial."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

FILES = [
    "resources/js/communications-webphone.js",
    "resources/js/communications-auto-dial.js",
    "resources/js/app.js",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    upload_files(ssh, pairs, REMOTE_APP)
    print(
        sudo_run(
            ssh,
            f"""
cd {REMOTE_APP} && npm run build > /tmp/vite-ringing-disposition.log 2>&1
tail -n 18 /tmp/vite-ringing-disposition.log
echo BUILD:$?
chown -R www-data:www-data {REMOTE_APP}/public/build
grep -n "refreshOutboundRingingUi\\|autoDialListenersBound\\|never reopen\\|_finalizeAfterByeInFlight\\|emitEnded" \\
  {REMOTE_APP}/resources/js/communications-webphone.js \\
  {REMOTE_APP}/resources/js/communications-auto-dial.js | head -40
""",
            check=False,
        )
    )
    ssh.close()
    print("Ringing + single disposition fix deployed. Ctrl+F5.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
