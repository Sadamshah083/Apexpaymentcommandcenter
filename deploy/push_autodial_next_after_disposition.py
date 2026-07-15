#!/usr/bin/env python3
"""Deploy auto-dial: clear saving status + start 10s next call after disposition."""

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
cd {REMOTE_APP} && npm run build > /tmp/vite-autodial-next.log 2>&1
tail -n 16 /tmp/vite-autodial-next.log
echo BUILD:$?
chown -R www-data:www-data {REMOTE_APP}/public/build
grep -n "finishDispositionSaveUi\\|continueAutoDial\\|Next call in 10s\\|waitingNext\\|AbortSignal.timeout" \\
  {REMOTE_APP}/resources/js/communications-auto-dial.js | head -40
""",
            check=False,
        )
    )
    ssh.close()
    print("Auto-dial next-call fix deployed. Ctrl+F5 dialer.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
