#!/usr/bin/env python3
"""Deploy: call logs show only real dispositions (no connected/initiated)."""
from __future__ import annotations

import sys
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]
NEW = {"host": "203.215.161.236", "user": "ateg", "password": "balitech1"}
REMOTE = "/var/www/apexone"
FILES = [
    "resources/views/communications/partials/call-log-row.blade.php",
    "resources/js/communications-dialer.js",
    "app/Services/Communications/CommunicationsInboxService.php",
    "app/Services/Communications/AgentStatusReportService.php",
    "resources/views/communications/agent-status/partials/panel.blade.php",
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
    inner = f"""
set -e
cd {REMOTE}
chown www-data:www-data {files_chown}
php -l app/Services/Communications/CommunicationsInboxService.php
php artisan view:clear
php artisan cache:clear
npm run build --silent
grep -n "result--disposition\\|resolveDisplayDisposition\\|statusLikeDispositions" \\
  resources/views/communications/partials/call-log-row.blade.php \\
  resources/js/communications-dialer.js \\
  app/Services/Communications/AgentStatusReportService.php | head -20
echo DONE_DISP_ONLY_CALL_LOGS
"""
    print(sudo_run(ssh, inner))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
