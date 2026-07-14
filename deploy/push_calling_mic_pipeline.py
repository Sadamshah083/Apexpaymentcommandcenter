#!/usr/bin/env python3
"""Test + deploy calling mic pipeline (constraints + speech gate)."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

JS = ROOT / "resources/js/communications-webphone.js"
DOC = ROOT / "docs/MORPHEUS_CALLING_INTEGRATION.md"

REQUIRED = [
    "buildWebphoneAudioConstraints",
    "buildWebphoneMediaConstraints",
    "startOutboundSpeechGate",
    "stopOutboundSpeechGate",
    "noiseSuppression",
    "echoCancellation",
    "SPEECH_GATE",
    "constraints: buildWebphoneMediaConstraints()",
]

FORBIDDEN = [
    "constraints: WEBPHONE_MEDIA_CONSTRAINTS",
    "const WEBPHONE_MEDIA_CONSTRAINTS",
]


def local_test() -> None:
    text = JS.read_text(encoding="utf-8")
    missing = [s for s in REQUIRED if s not in text]
    bad = [s for s in FORBIDDEN if s in text]
    if missing or bad:
        raise SystemExit(f"FAILED missing={missing} forbidden={bad}")
    print("Local calling-mic checks PASSED")


def main() -> int:
    local_test()
    pairs = [(JS, "resources/js/communications-webphone.js")]
    if DOC.is_file():
        pairs.append((DOC, "docs/MORPHEUS_CALLING_INTEGRATION.md"))
    ssh = connect()
    upload_files(ssh, pairs, REMOTE_APP)
    print(
        sudo_run(
            ssh,
            f"""
cd {REMOTE_APP} && npm run build > /tmp/vite-calling-mic.log 2>&1
tail -n 16 /tmp/vite-calling-mic.log
echo BUILD:$?
chown -R www-data:www-data {REMOTE_APP}/public/build
grep -n "buildWebphoneMediaConstraints\\|startOutboundSpeechGate\\|SPEECH_GATE" \\
  {REMOTE_APP}/resources/js/communications-webphone.js | head -20
""",
            check=False,
        )
    )
    ssh.close()
    print("Calling mic pipeline deployed. Ctrl+F5 Communications dialer.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
