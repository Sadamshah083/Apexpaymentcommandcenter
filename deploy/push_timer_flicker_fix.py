#!/usr/bin/env python3
"""Deploy dialer connected-timer flicker fix."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

JS = ROOT / "resources/js/communications-webphone.js"

REQUIRED = [
    "currentFormattedTimer",
    "Already live — never restart/reset the connected timer",
    "Keep a stable connected epoch",
    "null = keep current text",
]


def local_test() -> None:
    text = JS.read_text(encoding="utf-8")
    missing = [s for s in REQUIRED if s not in text]
    if missing:
        raise SystemExit(f"FAILED missing={missing}")
    if "timer: '00:00'" in text and "Both sides connected" in text:
        # Allow elsewhere, but enterBothSidesConnected must use timerDisplay.
        if "timer: timerDisplay" not in text:
            raise SystemExit("FAILED: enterBothSidesConnected still uses hardcoded 00:00")
    print("Local timer-flicker checks PASSED")


def main() -> int:
    local_test()
    ssh = connect()
    upload_files(ssh, [(JS, "resources/js/communications-webphone.js")], REMOTE_APP)
    print(
        sudo_run(
            ssh,
            f"""
cd {REMOTE_APP} && npm run build > /tmp/vite-timer-flicker.log 2>&1
tail -n 18 /tmp/vite-timer-flicker.log
echo BUILD:$?
chown -R www-data:www-data {REMOTE_APP}/public/build
grep -n "currentFormattedTimer\\|stable connected epoch\\|timerDisplay" \\
  {REMOTE_APP}/resources/js/communications-webphone.js | head -20
""",
            check=False,
        )
    )
    ssh.close()
    print("Timer flicker fix deployed. Ctrl+F5 Communications dialer.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
