#!/usr/bin/env python3
"""Deploy responsive monitoring/agent-status UI + remove CRM/Business Research."""
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
REMOTE_APP = "/var/www/apexone"

UPLOAD = [
    "resources/css/app.css",
    "resources/views/layouts/partials/sidebar-nav-admin.blade.php",
    "resources/views/layouts/admin.blade.php",
    "resources/views/dashboard.blade.php",
    "resources/views/communications/monitoring/partials/wallboard.blade.php",
    "resources/views/communications/agent-status/partials/panel.blade.php",
    "routes/web.php",
    "config/admin_modules.php",
    "app/Http/Controllers/DashboardController.php",
    "app/Providers/AppServiceProvider.php",
]

REMOTE_DELETE = [
    "app/Http/Controllers/CrmCampaignController.php",
    "app/Http/Controllers/BusinessResearchController.php",
    "app/Jobs/ProcessCrmCampaignJob.php",
    "app/Jobs/RunCrmLeadResearchJob.php",
    "app/Jobs/RunBusinessResearchJob.php",
    "app/Console/Commands/BackfillCrmLeadFingerprints.php",
    "config/crm.php",
    "resources/views/crm",
    "resources/views/business-research",
    "app/Services/Crm",
]


def main() -> int:
    sys.path.insert(0, str(ROOT))
    os.environ["DEPLOY_HOST"] = NEW["host"]
    os.environ["DEPLOY_USER"] = NEW["user"]
    os.environ["DEPLOY_PASSWORD"] = NEW["password"]
    import deploy._ssh as ssh_mod

    ssh_mod.HOST = NEW["host"]
    ssh_mod.USER = NEW["user"]
    ssh_mod.PASSWORD = NEW["password"]
    ssh_mod.REMOTE_APP = REMOTE_APP
    from deploy._ssh import upload_files

    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(NEW["host"], username=NEW["user"], password=NEW["password"], timeout=30)
    upload_files(ssh, [(ROOT / rel, rel) for rel in UPLOAD], app_root=REMOTE_APP)

    deletes = " ".join(shlex.quote(f"{REMOTE_APP}/{p}") for p in REMOTE_DELETE)
    cmd = f"""
set -e
cd {REMOTE_APP}
rm -rf {deletes}
./node_modules/.bin/vite build > /tmp/vite-ui-crm-remove.log 2>&1
echo BUILD:$?
tail -n 6 /tmp/vite-ui-crm-remove.log
chown -R www-data:www-data {REMOTE_APP}/public/build
sudo -u www-data php artisan view:clear
sudo -u www-data php artisan config:clear
sudo -u www-data php artisan route:clear
sudo -u www-data php artisan route:list --path=crm | head -5 || true
sudo -u www-data php artisan route:list --path=business-research | head -5 || true
! grep -q 'Business CRM' resources/views/layouts/partials/sidebar-nav-admin.blade.php && echo SIDEBAR_CRM_GONE
! grep -q 'Business Research' resources/views/layouts/partials/sidebar-nav-admin.blade.php && echo SIDEBAR_BR_GONE
grep -n 'align-items: stretch' resources/css/app.css | head -3
test ! -e app/Http/Controllers/CrmCampaignController.php && echo CTRL_CRM_GONE
test ! -e app/Http/Controllers/BusinessResearchController.php && echo CTRL_BR_GONE
echo DONE
"""
    full = f"echo {shlex.quote(NEW['password'])} | sudo -S -p '' bash -lc {shlex.quote(cmd)}"
    _, o, e = ssh.exec_command(full, timeout=240)
    print((o.read() + e.read()).decode(errors="replace"))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
