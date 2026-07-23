#!/usr/bin/env python3
from __future__ import annotations

import sys
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]
NEW = {"host": "203.215.161.236", "user": "ateg", "password": "balitech1"}
REMOTE = "/var/www/apexone"
FILES = [
    "app/Http/Controllers/CommunicationsHubController.php",
    "app/Services/Communications/CommunicationsCallHistoryService.php",
    "resources/js/communications-auto-dial.js",
]


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(NEW["host"], username=NEW["user"], password=NEW["password"], timeout=40)

    sys.path.insert(0, str(ROOT))
    import deploy._ssh as ssh_mod
    from deploy._ssh import sudo_run, upload_files

    ssh_mod.HOST = NEW["host"]
    ssh_mod.USER = NEW["user"]
    ssh_mod.PASSWORD = NEW["password"]
    ssh_mod.REMOTE_APP = REMOTE

    print(f"Uploading {len(FILES)} files...")
    upload_files(ssh, [(ROOT / rel, rel) for rel in FILES], app_root=REMOTE)
    files_chown = " ".join(f"{REMOTE}/{f}" for f in FILES)
    inner = f"""
set -e
cd {REMOTE}
chown www-data:www-data {files_chown}
php artisan view:clear
php -l app/Http/Controllers/CommunicationsHubController.php
npm run build --silent
grep -n "'async' => true\\|Critical path only\\|timeoutMs = 5000" \\
  app/Http/Controllers/CommunicationsHubController.php \\
  resources/js/communications-auto-dial.js | head -15
echo DONE_FAST_DISPOSITION
"""
    out = sudo_run(ssh, inner)
    print(out.encode("ascii", "replace").decode("ascii"))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
