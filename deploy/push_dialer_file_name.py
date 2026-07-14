#!/usr/bin/env python3
"""Deploy imported-leads file name + owner name + call-log file name."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, restart_queue_workers, sudo_run, upload_files

FILES = [
    "app/Services/Communications/DialerImportedLeadsService.php",
    "app/Services/Communications/CommunicationsLeadLookupService.php",
    "app/Services/Communications/CommunicationsInboxService.php",
    "resources/views/communications/partials/dialer-lead-row.blade.php",
    "resources/views/communications/partials/call-log-row.blade.php",
    "resources/js/communications-auto-dial.js",
    "resources/js/communications-dialer.js",
    "resources/css/comm-hub-ui-polish.css",
    "tests/Unit/Services/CommunicationsLeadLookupServiceTest.php",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    print(f"Uploading {len(pairs)} files...")
    upload_files(ssh, pairs, app_root=REMOTE_APP)

    cmd = (
        f"cd {REMOTE_APP} && "
        "rm -rf node_modules/.vite node_modules/.vite-temp && "
        "npm run build > /tmp/apex_vite_build.log 2>&1; "
        "echo EXIT:$? >> /tmp/apex_vite_build.log; "
        f"chown -R www-data:www-data {REMOTE_APP}/public/build; "
        "tail -n 20 /tmp/apex_vite_build.log"
    )
    print(sudo_run(ssh, cmd))
    print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear", check=False))

    try:
        restart_queue_workers(ssh)
    except RuntimeError as exc:
        print(f"Warning: queue restart skipped ({exc})")

    ssh.close()
    print("File name + owner name dialer update deployed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
