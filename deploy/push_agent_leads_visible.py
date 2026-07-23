#!/usr/bin/env python3
"""Deploy agent imported-leads visibility fix."""

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

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

FILES = [
    "app/Services/Communications/CommunicationsInboxService.php",
    "app/Http/Controllers/CommunicationsHubController.php",
    "resources/views/communications/partials/center-dialer-hub.blade.php",
    "resources/js/communications-auto-dial.js",
    "resources/css/comm-hub-ui-polish.css",
]


def main() -> None:
    pairs = [(ROOT / rel, rel.replace("\\", "/")) for rel in FILES]
    ssh = connect()
    try:
        upload_files(ssh, pairs)
        print(sudo_run(ssh, f"cd {REMOTE_APP} && npm run build 2>&1 | tail -25", check=False))
        print(sudo_run(ssh, f"cd {REMOTE_APP} && php artisan view:clear && php artisan route:clear", check=False))
        print(sudo_run(ssh, "systemctl reload php8.3-fpm 2>/dev/null || systemctl reload php-fpm 2>/dev/null || true", check=False))
        print("Done.")
    finally:
        ssh.close()


if __name__ == "__main__":
    main()
