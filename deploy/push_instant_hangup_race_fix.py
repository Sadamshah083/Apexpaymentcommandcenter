#!/usr/bin/env python3
"""Deploy instant hangup race fixes (originate abort + no ringing resurrect)."""

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
npm run build > /tmp/vite-instant-hangup.log 2>&1
tail -n 18 /tmp/vite-instant-hangup.log
echo BUILD:$?
chown -R www-data:www-data {REMOTE_APP}/public/build
grep -n "beginOutboundAttempt\\|cancelOutboundAttempt\\|killLateOriginate\\|isOutboundAttemptCurrent" \\
  {REMOTE_APP}/resources/js/communications-webphone.js \\
  {REMOTE_APP}/resources/js/communications-dialer.js | head -30
grep -n "refreshOutboundRingingUi\\|showClickToCallRinging" \\
  {REMOTE_APP}/resources/js/communications-dialer.js | head -15
""",
            check=False,
        )
    )
    ssh.close()
    print("Instant hangup fixes deployed. Hard refresh dialer (Ctrl+F5).")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
