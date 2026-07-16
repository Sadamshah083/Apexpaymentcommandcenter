#!/usr/bin/env python3
"""Deploy live Ringing/Connected UI lock + auto-dial hangup/echo fixes."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

FILES = [
    "resources/js/communications-webphone.js",
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
cd {REMOTE_APP}
npm run build > /tmp/vite-live-call-ui.log 2>&1
tail -n 20 /tmp/vite-live-call-ui.log
echo BUILD:$?
chown -R www-data:www-data {REMOTE_APP}/public/build
# Verify key guards exist in built + source
grep -n "isLiveCallUiActive\\|liveCallUiActive = true\\|ch-call-live" \\
  {REMOTE_APP}/resources/js/communications-webphone.js | head -40
grep -n "isLiveCallUiActive\\|Never open disposition" \\
  {REMOTE_APP}/resources/js/communications-auto-dial.js | head -20
# Ensure built bundle includes the live-call lock string
grep -l "isLiveCallUiActive\\|liveCallUiActive" {REMOTE_APP}/public/build/assets/communications*.js | head -5
""",
            check=False,
        )
    )
    ssh.close()
    print("Live call UI + auto-dial fix deployed. Hard refresh (Ctrl+F5).")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
