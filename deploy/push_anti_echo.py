#!/usr/bin/env python3
"""Local sanity checks for anti-echo webphone changes, then deploy."""

from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

JS = ROOT / "resources/js/communications-webphone.js"
BLADE = ROOT / "resources/views/communications/partials/webphone-panel.blade.php"

REQUIRED_SNIPPETS = [
    "REMOTE_PLAYBACK_VOLUME = 0.55",
    "rebuildLocalMicWithAntiEcho",
    "startAntiEchoKeepalive",
    "stopAntiEchoKeepalive",
    "WEBPHONE_AUDIO_CONSTRAINTS_STRICT",
    "echoCancellation: true",
    "releaseLocalMicStream",
]


def local_test() -> None:
    text = JS.read_text(encoding="utf-8")
    missing = [s for s in REQUIRED_SNIPPETS if s not in text]
    if missing:
        raise SystemExit(f"Local anti-echo test FAILED. Missing: {missing}")

    volume_match = re.search(r"REMOTE_PLAYBACK_VOLUME\s*=\s*([0-9.]+)", text)
    if not volume_match or float(volume_match.group(1)) > 0.65:
        raise SystemExit("Local anti-echo test FAILED: remote volume too high")

    blade = BLADE.read_text(encoding="utf-8")
    if "data-webphone-remote" not in blade:
        raise SystemExit("Local anti-echo test FAILED: remote audio element missing")

    print("Local anti-echo checks PASSED")
    print(f"  remote volume={volume_match.group(1)}")
    print("  rebuildLocalMicWithAntiEcho + keepalive present")


def main() -> int:
    local_test()
    pairs = [
        (JS, "resources/js/communications-webphone.js"),
        (BLADE, "resources/views/communications/partials/webphone-panel.blade.php"),
    ]
    ssh = connect()
    upload_files(ssh, pairs, REMOTE_APP)
    print(
        sudo_run(
            ssh,
            f"""
cd {REMOTE_APP} && npm run build > /tmp/vite-anti-echo.log 2>&1
tail -n 16 /tmp/vite-anti-echo.log
echo BUILD:$?
chown -R www-data:www-data {REMOTE_APP}/public/build
cd {REMOTE_APP} && sudo -u www-data php artisan view:clear
grep -n "REMOTE_PLAYBACK_VOLUME = 0.55\\|rebuildLocalMicWithAntiEcho\\|startAntiEchoKeepalive\\|WEBPHONE_AUDIO_CONSTRAINTS_STRICT" \\
  {REMOTE_APP}/resources/js/communications-webphone.js | head -20
""",
            check=False,
        )
    )
    ssh.close()
    print("Anti-echo fix tested + deployed. Ctrl+F5 Communications dialer.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
