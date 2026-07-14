#!/usr/bin/env python3
from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run_batch, upload_files

FILES = [
    "app/Services/Communications/MorpheusCallEventService.php",
    "app/Services/Communications/CallMonitoringService.php",
    "app/Services/Communications/MorpheusHubService.php",
    "app/Http/Controllers/MorpheusHubController.php",
    "routes/morpheus-communications.php",
    "resources/views/communications/partials/webphone-panel.blade.php",
    "resources/js/communications-webphone.js",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    print(f"Uploading {len(pairs)} files...")
    upload_files(ssh, pairs, app_root=REMOTE_APP)
    sudo_run_batch(
        ssh,
        [
            f"cd {REMOTE_APP} && sudo -u www-data php -l app/Services/Communications/MorpheusCallEventService.php",
            f"cd {REMOTE_APP} && sudo -u www-data php -l app/Http/Controllers/MorpheusHubController.php",
            f"cd {REMOTE_APP} && sudo -u www-data php artisan route:clear",
            f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear",
            f"cd {REMOTE_APP} && sudo -u www-data php artisan cache:clear",
            f"cd {REMOTE_APP} && npm run build > /tmp/vite-build.log 2>&1; echo BUILD:$?",
            f"chown -R www-data:www-data {REMOTE_APP}/public/build",
        ],
    )
    ssh.close()
    print("Realtime connected detection deployed. Hard refresh (Ctrl+F5) on dialer + monitoring.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
