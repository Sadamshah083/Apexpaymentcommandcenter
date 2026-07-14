#!/usr/bin/env python3
"""Deploy agent + team lead recordings tabs."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, sudo_run_batch, upload_files

FILES = [
    "app/Services/Communications/CommunicationsAccessService.php",
    "app/Services/Communications/CommunicationsCallHistoryService.php",
    "app/Services/Communications/CommunicationsInboxService.php",
    "app/Http/Controllers/CommunicationsHubController.php",
    "resources/views/communications/partials/center-dialer-hub.blade.php",
    "resources/views/communications/partials/dialer-recording-row.blade.php",
    "resources/js/communications-dialer.js",
    "resources/css/comm-hub-ui-polish.css",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    print(f"Uploading {len(pairs)} files...")
    upload_files(ssh, pairs, app_root=REMOTE_APP)
    print("Building assets + clearing caches...")
    sudo_run_batch(
        ssh,
        [
            f"cd {REMOTE_APP} && npm run build",
            f"chown -R www-data:www-data {REMOTE_APP}/public/build",
            f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear",
            f"cd {REMOTE_APP} && sudo -u www-data php artisan cache:clear",
        ],
    )
    print(sudo_run(ssh, "curl -fsS http://127.0.0.1/admin/login -o /dev/null -w '%{http_code}' || true"))
    ssh.close()
    print("Agent + team lead recordings deployed. Hard-refresh (Ctrl+F5).")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
