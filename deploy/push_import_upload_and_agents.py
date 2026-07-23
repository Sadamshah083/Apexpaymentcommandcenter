#!/usr/bin/env python3
"""Deploy import upload fix + assigned-agent column to NEW."""
from __future__ import annotations

import shlex
import sys
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]
NEW = {"host": "203.215.161.236", "user": "ateg", "password": "balitech1"}
REMOTE = "/var/www/apexone"
FILES = [
    "app/Http/Controllers/WorkflowController.php",
    "app/Services/Workflow/WorkflowDashboardService.php",
    "resources/views/workflows/create.blade.php",
    "resources/views/admin/dashboard/partials/imports-panel.blade.php",
    "resources/js/workflow-upload.js",
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
    from deploy._ssh import upload_files

    pairs = [(ROOT / rel, rel) for rel in FILES]
    missing = [str(p) for p, _ in pairs if not p.is_file()]
    if missing:
        print("Missing:", *missing, sep="\n ")
        return 1

    print(f"Uploading {len(pairs)} files...")
    upload_files(ssh, pairs, app_root=REMOTE)

    files_chown = " ".join(f"{REMOTE}/{f}" for f in FILES)
    inner = f"""
set -e
cd {REMOTE}
chown www-data:www-data {files_chown}
php -l app/Http/Controllers/WorkflowController.php
php -l app/Services/Workflow/WorkflowDashboardService.php
grep -n 'data-turbo=\"false\"\\|max:51200\\|attachAssignedAgentSummaries\\|import-assigned-agents' \
  resources/views/workflows/create.blade.php \
  app/Http/Controllers/WorkflowController.php \
  app/Services/Workflow/WorkflowDashboardService.php \
  resources/views/admin/dashboard/partials/imports-panel.blade.php | head -30
sudo -u www-data php artisan view:clear
sudo -u www-data php artisan config:clear
sudo -u www-data npm run build > /tmp/vite-import-fix.log 2>&1 || true
tail -n 12 /tmp/vite-import-fix.log
chown -R www-data:www-data public/build
systemctl reload php8.3-fpm 2>/dev/null || systemctl reload php8.2-fpm 2>/dev/null || true
echo DONE_IMPORT_FIX
"""
    cmd = f"echo {shlex.quote(NEW['password'])} | sudo -S -p '' bash -lc {shlex.quote(inner)}"
    _, o, e = ssh.exec_command(cmd, timeout=360)
    print((o.read() + e.read()).decode(errors="replace")[-12000:])
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
