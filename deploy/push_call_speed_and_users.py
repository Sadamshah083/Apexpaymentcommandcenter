#!/usr/bin/env python3
"""Deploy call-speed fixes and upsert team accounts with password 123456."""

from __future__ import annotations

import shlex
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import PASSWORD, REMOTE_APP, connect, restart_queue_workers, sudo_run, sudo_run_batch, upload_files

FILES = [
    "app/Services/Integrations/ZoomApiService.php",
    "app/Http/Controllers/MorpheusHubController.php",
    "scripts/upsert_team_accounts.php",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    print(f"Uploading {len(pairs)} files...")
    upload_files(ssh, pairs, app_root=REMOTE_APP)

    print("Clearing caches...")
    sudo_run_batch(
        ssh,
        [
            f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear",
            f"cd {REMOTE_APP} && sudo -u www-data php artisan config:clear",
            f"cd {REMOTE_APP} && sudo -u www-data php artisan config:cache",
            f"cd {REMOTE_APP} && sudo -u www-data php artisan route:clear",
        ],
    )

    try:
        restart_queue_workers(ssh)
    except RuntimeError as exc:
        print(f"Warning: queue restart skipped ({exc})")

    print("Upserting team accounts + resetting admin passwords...")
    out = sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php scripts/upsert_team_accounts.php")
    print(out)

    print(sudo_run(ssh, f"curl -fsS http://127.0.0.1/admin/login -o /dev/null -w '%{{http_code}}' || true"))
    ssh.close()
    print("Call speed + team accounts deployed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
