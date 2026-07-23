#!/usr/bin/env python3
"""Deploy All call logs disposition filter to NEW."""
from __future__ import annotations

import sys
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]
NEW = {"host": "203.215.161.236", "user": "ateg", "password": "balitech1"}
REMOTE = "/var/www/apexone"
FILES = [
    "app/Services/Communications/AgentStatusReportService.php",
    "app/Http/Controllers/AgentStatusReportController.php",
    "resources/views/communications/agent-status/partials/panel.blade.php",
    "resources/css/app.css",
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

    pairs = [(ROOT / rel, rel) for rel in FILES]
    print(f"Uploading {len(pairs)} files...")
    upload_files(ssh, pairs, app_root=REMOTE)

    files_chown = " ".join(f"{REMOTE}/{f}" for f in FILES)
    inner = f"""
set -e
cd {REMOTE}
chown www-data:www-data {files_chown}
php -l app/Services/Communications/AgentStatusReportService.php
php -l app/Http/Controllers/AgentStatusReportController.php
php artisan view:clear
php artisan config:clear
npm run build --silent
grep -n "disposition\\|All dispositions\\|applyDispositionFilter" \\
  app/Http/Controllers/AgentStatusReportController.php \\
  app/Services/Communications/AgentStatusReportService.php \\
  resources/views/communications/agent-status/partials/panel.blade.php | head -25
echo DONE_DISPOSITION_FILTER
"""
    print(sudo_run(ssh, inner))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
