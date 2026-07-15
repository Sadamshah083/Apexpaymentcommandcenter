#!/usr/bin/env python3
"""Deploy instant Connected detection (remove ~2s delay)."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

FILES = [
    "resources/js/communications-webphone.js",
    "app/Services/Integrations/ZoomApiService.php",
]


def main() -> int:
    text = (ROOT / FILES[0]).read_text(encoding="utf-8")
    if "&& Number(data.billsec ?? 0) >= 2" in text:
        raise SystemExit("FAILED: billsec>=2 still in webphone")
    if "Date.now() - this.agentLegEstablishedAt >= 800" in text:
        raise SystemExit("FAILED: 800ms agentReady delay still present")
    ssh = connect()
    upload_files(ssh, [(ROOT / f, f) for f in FILES], REMOTE_APP)
    print(
        sudo_run(
            ssh,
            f"""
cd {REMOTE_APP} && npm run build > /tmp/vite-instant-connect.log 2>&1
tail -n 16 /tmp/vite-instant-connect.log
echo BUILD:$?
chown -R www-data:www-data {REMOTE_APP}/public/build
grep -n "Instant Connected\\|billsec >= 1\\|delayMs = hasEvents ? 450" \\
  {REMOTE_APP}/resources/js/communications-webphone.js \\
  {REMOTE_APP}/app/Services/Integrations/ZoomApiService.php | head -25
php -l {REMOTE_APP}/app/Services/Integrations/ZoomApiService.php
""",
            check=False,
        )
    )
    ssh.close()
    print("Instant connect deployed. Ctrl+F5 dialer.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
