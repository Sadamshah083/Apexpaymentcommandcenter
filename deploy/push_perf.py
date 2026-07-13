#!/usr/bin/env python3
"""Deploy frontend performance optimizations."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, restart_queue_workers, sudo_run, sudo_run_batch, upload_files

FILES = [
    "resources/css/app.css",
    "resources/css/communications-hub.css",
    "resources/js/app.js",
    "resources/views/layouts/admin.blade.php",
    "resources/views/layouts/portal.blade.php",
    "resources/views/layouts/partials/vite-assets.blade.php",
    "vite.config.js",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    print(f"Uploading {len(pairs)} files...")
    upload_files(ssh, pairs, app_root=REMOTE_APP)
    print("Building frontend assets on server...")
    sudo_run_batch(ssh, [
        f"cd {REMOTE_APP} && npm run build",
        f"chown -R www-data:www-data {REMOTE_APP}/public/build",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear",
    ])
    try:
        restart_queue_workers(ssh)
    except RuntimeError as exc:
        print(f"Warning: queue restart skipped ({exc})")
    print(sudo_run(ssh, f"curl -fsS http://203.215.160.44/admin/login -o /dev/null -w '%{{http_code}}'"))
    ssh.close()
    print("Performance optimizations deployed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
