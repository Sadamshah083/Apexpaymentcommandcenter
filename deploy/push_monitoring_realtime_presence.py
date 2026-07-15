#!/usr/bin/env python3
"""Deploy realtime presence + SSE Call Monitoring."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, sudo_run_batch, upload_files

FILES = [
    "app/Services/Communications/AgentPresenceService.php",
    "app/Services/Communications/CallMonitoringService.php",
    "app/Http/Controllers/CallMonitoringController.php",
    "resources/views/layouts/portal.blade.php",
    "resources/js/communications-auto-dial.js",
    "resources/js/call-monitoring.js",
    "resources/js/app.js",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    print(f"Uploading {len(pairs)} files...")
    upload_files(ssh, pairs, REMOTE_APP)
    print(
        sudo_run(
            ssh,
            f"""
php -l {REMOTE_APP}/app/Services/Communications/AgentPresenceService.php
php -l {REMOTE_APP}/app/Http/Controllers/CallMonitoringController.php
""",
            check=False,
        )
    )
    print("Building assets...")
    sudo_run_batch(
        ssh,
        [
            f"cd {REMOTE_APP} && npm run build",
            f"chown -R www-data:www-data {REMOTE_APP}/public/build",
            f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear",
            f"cd {REMOTE_APP} && sudo -u www-data php artisan optimize:clear",
        ],
    )
    print(
        sudo_run(
            ssh,
            f"""
grep -n "bumpPresenceVersion\\|presence_version\\|connectStream\\|data-presence-url\\|initAgentPresence" \\
  {REMOTE_APP}/app/Services/Communications/AgentPresenceService.php \\
  {REMOTE_APP}/resources/js/call-monitoring.js \\
  {REMOTE_APP}/resources/views/layouts/portal.blade.php \\
  {REMOTE_APP}/resources/js/app.js | head -30
""",
            check=False,
        )
    )
    ssh.close()
    print("Realtime presence + SSE deployed. Ctrl+F5.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
