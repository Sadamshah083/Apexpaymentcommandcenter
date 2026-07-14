#!/usr/bin/env python3
from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run_batch, upload_files

FILES = [
    "app/Support/ReleaseSessionLock.php",
    "app/Http/Controllers/CallMonitoringController.php",
    "app/Http/Controllers/WorkspaceSyncController.php",
    "app/Http/Controllers/MorpheusHubController.php",
    "app/Services/Communications/CallMonitoringService.php",
    "resources/js/app.js",
    "resources/css/app.css",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    print(f"Uploading {len(pairs)} files...")
    upload_files(ssh, pairs, app_root=REMOTE_APP)
    sudo_run_batch(
        ssh,
        [
            f"cd {REMOTE_APP} && npm run build > /tmp/vite-build.log 2>&1; echo BUILD:$?",
            f"chown -R www-data:www-data {REMOTE_APP}/public/build",
            f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear",
            f"cd {REMOTE_APP} && sudo -u www-data php artisan cache:clear",
        ],
    )
    ssh.close()
    print("Nav speed fix deployed. Ctrl+F5.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
