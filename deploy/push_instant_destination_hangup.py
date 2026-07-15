#!/usr/bin/env python3
"""Deploy instant destination hangup when agent ends the call."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

FILES = [
    "resources/js/communications-webphone.js",
    "app/Services/Integrations/ZoomApiService.php",
    "app/Http/Controllers/MorpheusHubController.php",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES]
    ssh = connect()
    upload_files(ssh, pairs, REMOTE_APP)
    print(
        sudo_run(
            ssh,
            f"""
cd {REMOTE_APP} && npm run build > /tmp/vite-dest-hangup.log 2>&1
tail -n 18 /tmp/vite-dest-hangup.log
echo BUILD:$?
chown -R www-data:www-data {REMOTE_APP}/public/build
grep -n "killDestinationLegsNow\\|Instant priority\\|ReleaseSessionLock::now" \\
  {REMOTE_APP}/resources/js/communications-webphone.js \\
  {REMOTE_APP}/app/Services/Integrations/ZoomApiService.php \\
  {REMOTE_APP}/app/Http/Controllers/MorpheusHubController.php | head -25
php -l {REMOTE_APP}/app/Services/Integrations/ZoomApiService.php
php -l {REMOTE_APP}/app/Http/Controllers/MorpheusHubController.php
""",
            check=False,
        )
    )
    ssh.close()
    print("Instant destination hangup deployed. Ctrl+F5 dialer.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
