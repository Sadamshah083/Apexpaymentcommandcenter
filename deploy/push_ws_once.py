#!/usr/bin/env python3
"""Deploy single WebSocket-per-call fix + clear Laravel/Vite caches."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

FILES = [
    "resources/js/communications-webphone.js",
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
npm run build > /tmp/vite-ws-once.log 2>&1
tail -n 20 /tmp/vite-ws-once.log
echo BUILD:$?
php artisan view:clear
php artisan cache:clear
php artisan config:clear
chown -R www-data:www-data {REMOTE_APP}/public/build
# Prove new bundle is live (not old B2SoB5R hash)
ls -1 {REMOTE_APP}/public/build/assets/communications-*.js
grep -n "sharedCallEventsUuid\\|Never reopen WebSocket\\|Never stop/reopen" \\
  {REMOTE_APP}/resources/js/communications-webphone.js | head -15
grep -o "sharedCallEventsUuid" {REMOTE_APP}/public/build/assets/communications-*.js | head -3
""",
            check=False,
        )
    )
    ssh.close()
    print("Single WebSocket fix deployed. Hard refresh with Ctrl+Shift+R.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
