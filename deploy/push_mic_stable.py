#!/usr/bin/env python3
"""Test + deploy: quieter background noise, stable mic (no voice drops)."""

from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

JS = ROOT / "resources/js/communications-webphone.js"

REQUIRED = [
    "autoGainControl: false",
    "noiseSuppression: true",
    "tuneLocalMicForSpeech",
    "retuneMic = false",
    "never re-apply mic constraints",
    "REMOTE_PLAYBACK_VOLUME = 0.62",
]

FORBIDDEN = [
    "rebuildLocalMicWithAntiEcho",
    "WEBPHONE_AUDIO_CONSTRAINTS_STRICT",
]


def local_test() -> None:
    text = JS.read_text(encoding="utf-8")
    missing = [s for s in REQUIRED if s not in text]
    present_bad = [s for s in FORBIDDEN if s in text]
    if missing or present_bad:
        raise SystemExit(f"Mic audio test FAILED missing={missing} forbidden_still_present={present_bad}")

    if "replaceTrack" in text and "rebuildLocalMic" in text:
        raise SystemExit("Mic audio test FAILED: mid-call replaceTrack path still present")

    vol = float(re.search(r"REMOTE_PLAYBACK_VOLUME\s*=\s*([0-9.]+)", text).group(1))
    print("Local mic/noise checks PASSED")
    print(f"  volume={vol}, AGC off, no mid-call mic rebuild")


def main() -> int:
    local_test()
    ssh = connect()
    upload_files(ssh, [(JS, "resources/js/communications-webphone.js")], REMOTE_APP)
    print(
        sudo_run(
            ssh,
            f"""
cd {REMOTE_APP} && npm run build > /tmp/vite-mic-stable.log 2>&1
tail -n 16 /tmp/vite-mic-stable.log
echo BUILD:$?
chown -R www-data:www-data {REMOTE_APP}/public/build
grep -n "autoGainControl: false\\|tuneLocalMicForSpeech\\|rebuildLocalMicWithAntiEcho\\|REMOTE_PLAYBACK_VOLUME = 0.62" \\
  {REMOTE_APP}/resources/js/communications-webphone.js | head -20
""",
            check=False,
        )
    )
    ssh.close()
    print("Stable mic + noise fix deployed. Ctrl+F5.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
