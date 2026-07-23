#!/usr/bin/env python3
"""Deploy assigned-agents +more popup and Google Roboto font across CRM."""
from __future__ import annotations

import sys
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]
NEW = {"host": "203.215.161.236", "user": "ateg", "password": "balitech1"}
REMOTE = "/var/www/apexone"
FILES = [
    "resources/views/layouts/partials/google-fonts.blade.php",
    "resources/views/layouts/admin.blade.php",
    "resources/views/layouts/portal.blade.php",
    "resources/css/app.css",
    "resources/css/comm-hub-ghl-theme.css",
    "resources/views/admin/dashboard/partials/imports-panel.blade.php",
    "resources/js/workspace-sync.js",
]


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(NEW["host"], username=NEW["user"], password=NEW["password"], timeout=40)

    sys.path.insert(0, str(ROOT))
    import deploy._ssh as ssh_mod

    ssh_mod.HOST = NEW["host"]
    ssh_mod.USER = NEW["user"]
    ssh_mod.PASSWORD = NEW["password"]
    ssh_mod.REMOTE_APP = REMOTE
    from deploy._ssh import sudo_run, upload_files

    print(f"Uploading {len(FILES)} files...")
    upload_files(ssh, [(ROOT / rel, rel) for rel in FILES], app_root=REMOTE)
    files_chown = " ".join(f"{REMOTE}/{f}" for f in FILES)
    # Also ensure communications-hub.css rebuild picks ghl theme
    inner = f"""
set -e
cd {REMOTE}
chown www-data:www-data {files_chown}
php artisan view:clear
npm run build --silent
grep -n "assigned-agents-more\\|Roboto\\|import-assigned-agents-modal" \\
  resources/views/admin/dashboard/partials/imports-panel.blade.php \\
  resources/views/layouts/partials/google-fonts.blade.php \\
  resources/css/app.css \\
  resources/js/workspace-sync.js | head -25
echo DONE_ASSIGNED_MORE_ROBOTO
"""
    print(sudo_run(ssh, inner))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
