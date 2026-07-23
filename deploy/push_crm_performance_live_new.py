#!/usr/bin/env python3
"""Re-deploy CRM performance optimizations to NEW and verify live."""
from __future__ import annotations

import os
import shlex
import sys
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]
NEW = {
    "host": "203.215.161.236",
    "user": "ateg",
    "password": os.environ.get("NEW_DEPLOY_PASSWORD", "balitech1"),
}
REMOTE = "/var/www/apexone"
FILES = [
    "app/Services/Communications/CommunicationsAgentService.php",
    "app/Services/Communications/CallMonitoringService.php",
    "app/Services/Communications/AgentStatusReportService.php",
    "app/Services/Communications/DialerImportedLeadsService.php",
    "app/Http/Controllers/CommunicationsHubController.php",
    "app/Services/Workspace/WorkspaceMemberService.php",
    "database/migrations/2026_07_22_220000_add_performance_indexes_for_monitoring_and_dialer.php",
    "resources/js/fast-nav.js",
    "resources/js/workspace-sync.js",
    "resources/js/communications-auto-dial.js",
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
    from deploy._ssh import upload_files

    pairs = [(ROOT / rel, rel) for rel in FILES]
    missing = [str(p) for p, _ in pairs if not p.is_file()]
    if missing:
        print("Missing:")
        for path in missing:
            print(f"  - {path}")
        return 1

    print(f"Uploading {len(pairs)} files to NEW...")
    upload_files(ssh, pairs, app_root=REMOTE)

    files_chown = " ".join(f"{REMOTE}/{f}" for f in FILES)
    inner = f"""
set -e
cd {REMOTE}
chown www-data:www-data {files_chown}
php -l app/Services/Communications/CommunicationsAgentService.php
php -l app/Services/Communications/CallMonitoringService.php
sudo -u www-data php artisan migrate --force --path=database/migrations/2026_07_22_220000_add_performance_indexes_for_monitoring_and_dialer.php
sudo -u www-data php artisan config:clear
sudo -u www-data php artisan view:clear
sudo -u www-data php artisan cache:clear
sudo -u www-data php artisan route:clear
sudo -u www-data php artisan optimize:clear
if [ -f package.json ]; then
  sudo -u www-data npm run build > /tmp/vite-perf-live.log 2>&1 || true
  tail -n 20 /tmp/vite-perf-live.log || true
  chown -R www-data:www-data public/build || true
fi
# bounce php-fpm if present so opcache picks up PHP
if systemctl list-units --type=service 2>/dev/null | grep -q php.*fpm; then
  systemctl reload php8.3-fpm 2>/dev/null || systemctl reload php8.2-fpm 2>/dev/null || systemctl reload php-fpm 2>/dev/null || true
  echo FPM_RELOADED
fi
echo VERIFY_PHP
grep -c loadActiveWorkspaceMembers app/Services/Communications/CommunicationsAgentService.php
grep -c cm:local_uuid app/Services/Communications/CallMonitoringService.php
grep -c 'COUNT(*) as cnt' app/Services/Communications/AgentStatusReportService.php
echo VERIFY_JS
grep -c 'MAX_IDLE_PREFETCH = 3' resources/js/fast-nav.js
grep -c 'SYNC_FULL_POLL_MS = 10000' resources/js/workspace-sync.js
grep -n '1500' resources/js/communications-auto-dial.js | head -3
echo VERIFY_BUILD
ls -lt public/build/manifest.json public/build/assets/app-*.js 2>/dev/null | head -5
echo DONE_LIVE
"""
    cmd = f"echo {shlex.quote(NEW['password'])} | sudo -S -p '' bash -lc {shlex.quote(inner)}"
    _, o, e = ssh.exec_command(cmd, timeout=360)
    print((o.read() + e.read()).decode(errors="replace")[-14000:])
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
