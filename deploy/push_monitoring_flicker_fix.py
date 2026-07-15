#!/usr/bin/env python3
"""Deploy Call Monitoring flicker fix."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, sudo_run_batch, upload_files

FILES = [
    "resources/js/call-monitoring.js",
    "resources/views/communications/monitoring/partials/wallboard.blade.php",
    "app/Services/Communications/CallMonitoringService.php",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    print(f"Uploading {len(pairs)} files...")
    upload_files(ssh, pairs, REMOTE_APP)
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
            f"grep -n STICKY_ROW_MS {REMOTE_APP}/resources/js/call-monitoring.js | head -5",
            check=False,
        )
    )
    ssh.close()
    print("Flicker fix deployed. Ctrl+F5.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
