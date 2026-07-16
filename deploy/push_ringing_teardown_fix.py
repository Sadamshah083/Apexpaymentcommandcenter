#!/usr/bin/env python3
"""Deploy hangup teardown + disposition label + ringer responsive fixes."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, restart_queue_workers, sudo_run, upload_files

FILES = [
    "config/integrations.php",
    "resources/js/communications-webphone.js",
    "resources/js/communications-auto-dial.js",
    "resources/views/communications/partials/call-summary-modal.blade.php",
    "resources/css/comm-hub-ghl-theme.css",
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
            "npm run build > /tmp/apex_ring_fix.log 2>&1; "
            "echo EXIT:$? >> /tmp/apex_ring_fix.log; "
            f"chown -R www-data:www-data {REMOTE_APP}/public/build; "
            "sudo -u www-data php artisan view:clear; "
            "sudo -u www-data php artisan config:clear; "
            "tail -n 25 /tmp/apex_ring_fix.log",
        )
    )
    try:
        restart_queue_workers(ssh)
    except RuntimeError as exc:
        print(f"Warning: queue restart skipped ({exc})")
    ssh.close()
    print("Ringing teardown + disposition labels deployed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
