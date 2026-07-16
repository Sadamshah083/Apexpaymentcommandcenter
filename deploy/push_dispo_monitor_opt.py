#!/usr/bin/env python3
"""Deploy disposition reopen + monitoring duplicate + fast originate fixes."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, restart_queue_workers, sudo_run, upload_files

FILES = [
    "resources/js/communications-auto-dial.js",
    "resources/js/communications-dialer.js",
    "resources/js/communications-webphone.js",
    "app/Services/Communications/CallMonitoringService.php",
    "app/Services/Communications/MorpheusCallEventService.php",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    print(f"Uploading {len(pairs)} files...")
    upload_files(ssh, pairs, app_root=REMOTE_APP)
    print(
        sudo_run(
            ssh,
            f"cd {REMOTE_APP} && "
            "rm -rf node_modules/.vite node_modules/.vite-temp && "
            "npm run build > /tmp/apex_dispo_opt.log 2>&1; "
            "echo EXIT:$? >> /tmp/apex_dispo_opt.log; "
            f"chown -R www-data:www-data {REMOTE_APP}/public/build; "
            "sudo -u www-data php artisan optimize:clear; "
            "tail -n 20 /tmp/apex_dispo_opt.log",
        )
    )
    try:
        restart_queue_workers(ssh)
    except RuntimeError as exc:
        print(f"Warning: queue restart skipped ({exc})")
    ssh.close()
    print("Disposition / monitoring / originate optimizations deployed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
