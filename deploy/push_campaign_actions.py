#!/usr/bin/env python3
"""Deploy campaigns edit/delete actions."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, sudo_run_batch, upload_files

FILES = [
    "resources/views/campaigns/index.blade.php",
    "resources/css/app.css",
    "app/Http/Controllers/CampaignController.php",
    "app/Services/Pipeline/CampaignService.php",
    "routes/web.php",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    print(f"Uploading {len(pairs)} files...")
    upload_files(ssh, pairs, app_root=REMOTE_APP)
    sudo_run_batch(
        ssh,
        [
            f"cd {REMOTE_APP} && npm run build",
            f"chown -R www-data:www-data {REMOTE_APP}/public/build",
            f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear",
            f"cd {REMOTE_APP} && sudo -u www-data php artisan route:clear",
            f"cd {REMOTE_APP} && sudo -u www-data php artisan route:cache",
        ],
    )
    print(sudo_run(ssh, "curl -fsS http://127.0.0.1/admin/login -o /dev/null -w '%{http_code}' || true"))
    ssh.close()
    print("Campaign edit/delete deployed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
