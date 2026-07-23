#!/usr/bin/env python3
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

from deploy._ssh import REMOTE_APP, connect, sudo_run


def main() -> None:
    ssh = connect()
    try:
        cmds = [
            f"cd {REMOTE_APP} && php artisan migrate --force --no-interaction",
            f"grep -n recent-by-phone {REMOTE_APP}/routes/web.php | head -5",
            f"grep -n 'Total dials' {REMOTE_APP}/resources/views/communications/partials/center-dialer-hub.blade.php | head -5",
            f"grep -n data-dialer-recent-preview {REMOTE_APP}/resources/views/communications/partials/dialer-form.blade.php | head -5",
            f"grep -n scheduleRecentDialPreview {REMOTE_APP}/resources/js/communications-dialer.js | head -5",
            f"cd {REMOTE_APP} && npm run build 2>&1 | tail -50",
            f"cd {REMOTE_APP} && php artisan view:clear && php artisan route:clear && php artisan opcache:clear 2>/dev/null || true",
        ]
        for cmd in cmds:
            print("====", cmd[:90], "====")
            print(sudo_run(ssh, cmd, check=False))
            print()
    finally:
        ssh.close()


if __name__ == "__main__":
    main()
