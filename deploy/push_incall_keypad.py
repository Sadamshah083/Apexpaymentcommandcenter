#!/usr/bin/env python3
"""Deploy in-call keypad + transfer popup."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, sudo_run_batch, upload_files

FILES = [
    "resources/views/communications/partials/dialer-form.blade.php",
    "resources/js/communications-webphone.js",
    "resources/css/comm-hub-ghl-theme.css",
    "resources/css/comm-hub-ui-polish.css",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    print(f"Uploading {len(pairs)} files...")
    upload_files(ssh, pairs, app_root=REMOTE_APP)
    print("Building assets...")
    sudo_run_batch(
        ssh,
        [
            f"cd {REMOTE_APP} && npm run build",
            f"chown -R www-data:www-data {REMOTE_APP}/public/build",
            f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear",
        ],
    )
    print(sudo_run(ssh, "curl -fsS http://127.0.0.1/admin/login -o /dev/null -w '%{http_code}' || true"))
    ssh.close()
    print("In-call keypad + transfer popup deployed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
