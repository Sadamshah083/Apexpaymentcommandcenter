#!/usr/bin/env python3
"""Fix admin file-restrict column (sync overwrite), list sync poll, SIP 503 retry."""

from __future__ import annotations

import os
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
os.environ.setdefault("DEPLOY_PASSWORD", "balitech1")

import deploy._ssh as ssh_mod

ssh_mod.HOST = "203.215.161.236"
ssh_mod.USER = "ateg"
ssh_mod.PASSWORD = "balitech1"
ssh_mod.REMOTE_APP = "/var/www/apexone"

from deploy._ssh import REMOTE_APP, connect, sudo_run_batch, upload_files

FILES = [
    "app/Services/Workspace/WorkspaceSyncService.php",
    "resources/views/admin/dashboard/partials/imports-panel.blade.php",
    "resources/js/workspace-sync.js",
    "resources/js/communications-webphone.js",
]


def main() -> None:
    pairs = [(ROOT / rel, rel.replace("\\", "/")) for rel in FILES]
    missing = [str(local) for local, _ in pairs if not local.exists()]
    if missing:
        raise SystemExit("Missing files:\n" + "\n".join(missing))

    ssh = connect()
    try:
        upload_files(ssh, pairs)
        sudo_run_batch(
            ssh,
            [
                f"cd {REMOTE_APP} && php artisan view:clear",
                f"cd {REMOTE_APP} && php artisan cache:clear",
                f"cd {REMOTE_APP} && php artisan config:clear",
                f"cd {REMOTE_APP} && (command -v npm >/dev/null && npm run build || true)",
                f"cd {REMOTE_APP} && php artisan opcache:clear 2>/dev/null || true",
                "systemctl reload php8.3-fpm 2>/dev/null || systemctl reload php-fpm 2>/dev/null || true",
            ],
            check=False,
        )
        print("Done.")
    finally:
        ssh.close()


if __name__ == "__main__":
    main()
