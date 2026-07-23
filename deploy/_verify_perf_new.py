#!/usr/bin/env python3
"""Finish verify + ensure AgentStatusReportService is live on NEW."""
from __future__ import annotations

import shlex
import sys
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]
NEW = {"host": "203.215.161.236", "user": "ateg", "password": "balitech1"}
REMOTE = "/var/www/apexone"


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
    from deploy._ssh import upload_files

    upload_files(
        ssh,
        [(ROOT / "app/Services/Communications/AgentStatusReportService.php", "app/Services/Communications/AgentStatusReportService.php")],
        app_root=REMOTE,
    )

    inner = f"""
cd {REMOTE}
chown www-data:www-data app/Services/Communications/AgentStatusReportService.php
systemctl reload php8.3-fpm 2>/dev/null || systemctl reload php8.2-fpm 2>/dev/null || true
echo --- callTotals ---
sed -n '108,125p' app/Services/Communications/AgentStatusReportService.php
echo --- enrichRecordings ---
grep -n enrichRecordings app/Http/Controllers/CommunicationsHubController.php | head -5
echo --- JS ---
grep -n 'MAX_IDLE_PREFETCH' resources/js/fast-nav.js | head -2
grep -n 'SYNC_FULL_POLL_MS' resources/js/workspace-sync.js | head -2
grep -n 'setInterval' resources/js/communications-auto-dial.js | head -5
grep -n '1500' resources/js/communications-auto-dial.js | head -5
echo --- indexes ---
sudo -u www-data php artisan tinker --execute="echo implode(',', array_column(DB::select('SHOW INDEX FROM communication_call_logs WHERE Key_name LIKE \\'ccl_%\\''), 'Key_name'));"
echo
echo LIVE_OK
"""
    cmd = f"echo {shlex.quote(NEW['password'])} | sudo -S -p '' bash -lc {shlex.quote(inner)}"
    _, o, e = ssh.exec_command(cmd, timeout=120)
    print((o.read() + e.read()).decode(errors="replace")[-8000:])
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
