#!/usr/bin/env python3
"""Deploy agent-status page speed fix (no sync Morpheus recording lookups)."""

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
    "app/Services/Communications/AgentStatusReportService.php",
    "app/Http/Controllers/AgentStatusReportController.php",
]


def main() -> None:
    pairs = [(ROOT / rel, rel.replace("\\", "/")) for rel in FILES]
    ssh = connect()
    try:
        upload_files(ssh, pairs)
        sudo_run_batch(
            ssh,
            [
                f"cd {REMOTE_APP} && php artisan view:clear",
                f"cd {REMOTE_APP} && php artisan cache:clear",
                f"cd {REMOTE_APP} && php artisan opcache:clear 2>/dev/null || true",
            ],
            check=False,
        )
        # Ensure PHP-FPM picks up new code
        sudo_run_batch(
            ssh,
            [
                "systemctl reload php8.3-fpm 2>/dev/null || systemctl reload php-fpm 2>/dev/null || true",
            ],
            check=False,
        )
        print("Done.")
    finally:
        ssh.close()


if __name__ == "__main__":
    main()
