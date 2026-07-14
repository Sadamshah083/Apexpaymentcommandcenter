#!/usr/bin/env python3
"""Deploy WebRTC mid-call recovery (grace + ICE restart, speech gate off, optional TURN)."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

JS = ROOT / "resources/js/communications-webphone.js"
PHP_CFG = ROOT / "config/integrations.php"
PHP_SVC = ROOT / "app/Services/Communications/CommunicationsWebphoneService.php"
DOC = ROOT / "docs/MORPHEUS_CALLING_INTEGRATION.md"

REQUIRED = [
    "attachWebRtcConnectionRecovery",
    "WEBRTC_DISCONNECT_GRACE_MS",
    "buildIceServers",
    "enabled: false",
    "turn_urls",
]


def local_test() -> None:
    text = JS.read_text(encoding="utf-8")
    missing = [s for s in REQUIRED if s not in text]
    if missing:
        raise SystemExit(f"FAILED missing={missing}")
    cfg = PHP_CFG.read_text(encoding="utf-8")
    svc = PHP_SVC.read_text(encoding="utf-8")
    if "MORPHEUS_TURN_URLS" not in cfg:
        raise SystemExit("FAILED: config missing MORPHEUS_TURN_URLS")
    if "'turn_urls'" not in svc:
        raise SystemExit("FAILED: webphone service missing turn_urls")
    print("Local WebRTC recovery checks PASSED")


def main() -> int:
    local_test()
    pairs = [
        (JS, "resources/js/communications-webphone.js"),
        (PHP_CFG, "config/integrations.php"),
        (PHP_SVC, "app/Services/Communications/CommunicationsWebphoneService.php"),
    ]
    if DOC.is_file():
        pairs.append((DOC, "docs/MORPHEUS_CALLING_INTEGRATION.md"))

    ssh = connect()
    upload_files(ssh, pairs, REMOTE_APP)
    print(
        sudo_run(
            ssh,
            f"""
cd {REMOTE_APP} && php artisan config:clear >/dev/null 2>&1 || true
cd {REMOTE_APP} && npm run build > /tmp/vite-webrtc-recovery.log 2>&1
tail -n 20 /tmp/vite-webrtc-recovery.log
echo BUILD:$?
chown -R www-data:www-data {REMOTE_APP}/public/build
grep -n "attachWebRtcConnectionRecovery\\|WEBRTC_DISCONNECT_GRACE_MS\\|buildIceServers\\|SPEECH_GATE" \\
  {REMOTE_APP}/resources/js/communications-webphone.js | head -25
grep -n "turn_urls\\|MORPHEUS_TURN" {REMOTE_APP}/config/integrations.php {REMOTE_APP}/app/Services/Communications/CommunicationsWebphoneService.php | head -20
""",
            check=False,
        )
    )
    ssh.close()
    print("WebRTC call recovery deployed. Ctrl+F5 Communications dialer.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
