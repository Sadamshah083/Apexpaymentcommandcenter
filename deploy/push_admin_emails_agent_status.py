#!/usr/bin/env python3
"""Deploy admin email rename, agent status report, green CTAs, deployment notice."""
from __future__ import annotations

import os
import shlex
import sys
import time
import urllib.request
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]
NEW = {
    "host": "203.215.161.236",
    "user": "ateg",
    "password": os.environ.get("NEW_DEPLOY_PASSWORD", "balitech1"),
}
REMOTE_APP = "/var/www/apexone"
FILES = [
    "config/deployment.php",
    "database/seeders/ApexPaymentsWorkspaceSeeder.php",
    "app/Http/Controllers/AgentStatusReportController.php",
    "app/Services/Communications/AgentStatusReportService.php",
    "routes/web.php",
    "resources/views/layouts/admin.blade.php",
    "resources/views/layouts/portal.blade.php",
    "resources/views/layouts/partials/deployment-notice.blade.php",
    "resources/views/layouts/partials/sidebar-nav-admin.blade.php",
    "resources/views/layouts/partials/sidebar-nav-portal.blade.php",
    "resources/views/components/import-file-link.blade.php",
    "resources/views/admin/dashboard/partials/imports-panel.blade.php",
    "resources/views/workflows/create.blade.php",
    "resources/views/communications/agent-status/index.blade.php",
    "resources/views/communications/agent-status/portal.blade.php",
    "resources/views/communications/agent-status/partials/panel.blade.php",
    "resources/views/communications/monitoring/partials/wallboard.blade.php",
    "resources/views/communications/inbox/partials/toolbar.blade.php",
    "resources/css/app.css",
    "resources/css/comm-hub-ghl-theme.css",
    "resources/js/call-monitoring.js",
    "resources/js/workspace-sync.js",
]


def http_probe(url: str, timeout: float = 20.0) -> tuple[int, float]:
    started = time.perf_counter()
    req = urllib.request.Request(url, method="GET", headers={"User-Agent": "apex-deploy-probe"})
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            resp.read(2048)
            return int(resp.status), (time.perf_counter() - started) * 1000.0
    except Exception as exc:  # noqa: BLE001
        return 0, (time.perf_counter() - started) * 1000.0


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
    upload_files(ssh, [(ROOT / rel, rel) for rel in FILES], app_root=REMOTE_APP)

    cmd = f"""
set -e
cd {REMOTE_APP}

# Ensure deployment notice is on for this release
if grep -q '^DEPLOYMENT_NOTICE_ENABLED=' .env; then
  sed -i 's/^DEPLOYMENT_NOTICE_ENABLED=.*/DEPLOYMENT_NOTICE_ENABLED=true/' .env
else
  printf '\\nDEPLOYMENT_NOTICE_ENABLED=true\\nDEPLOYMENT_NOTICE_VERSION=2026-07-16-a\\n' >> .env
fi
if ! grep -q '^DEPLOYMENT_NOTICE_VERSION=' .env; then
  printf 'DEPLOYMENT_NOTICE_VERSION=2026-07-16-a\\n' >> .env
else
  sed -i 's/^DEPLOYMENT_NOTICE_VERSION=.*/DEPLOYMENT_NOTICE_VERSION=2026-07-16-a/' .env
fi

./node_modules/.bin/vite build > /tmp/vite-agent-status.log 2>&1
echo BUILD:$?
tail -n 8 /tmp/vite-agent-status.log
chown -R www-data:www-data {REMOTE_APP}/public/build

sudo -u www-data php artisan config:clear
sudo -u www-data php artisan view:clear
sudo -u www-data php artisan route:clear

# Rename admin accounts; keep password as 123456
sudo -u www-data php artisan tinker --execute="
\\$hash = Illuminate\\\\Support\\\\Facades\\\\Hash::make('123456');
\\$ops = App\\\\Models\\\\User::query()->where('email', 'admin_ops_74b@apexpayments.com')->orWhere('name', 'admin_ops_74b')->orWhere('email', 'admin@apexonepayment.com')->first();
if (\\$ops) {{ \\$ops->forceFill(['email'=>'admin@apexonepayment.com','name'=>'admin','password'=>\\$hash])->save(); echo 'OPS:'.\\$ops->id.PHP_EOL; }} else {{ echo 'OPS:missing'.PHP_EOL; }}
\\$sup = App\\\\Models\\\\User::query()->where('email', 'admin_super_91a@apexpayments.com')->orWhere('name', 'admin_super_91a')->orWhere('email', 'superadmin@apexonepayment.com')->first();
if (\\$sup) {{ \\$sup->forceFill(['email'=>'superadmin@apexonepayment.com','name'=>'superadmin','password'=>\\$hash])->save(); echo 'SUP:'.\\$sup->id.PHP_EOL; }} else {{ echo 'SUP:missing'.PHP_EOL; }}
"

# Route sanity
sudo -u www-data php artisan route:list --path=agent-status | head -20
grep -n \"app-btn-success\" resources/css/app.css | head -3
grep -n \"Team Lead Status\" resources/views/layouts/partials/sidebar-nav-admin.blade.php | head -3
test -f app/Http/Controllers/AgentStatusReportController.php && echo CONTROLLER_OK
echo DONE
"""
    full = f"echo {shlex.quote(NEW['password'])} | sudo -S -p '' bash -lc {shlex.quote(cmd)}"
    _, o, e = ssh.exec_command(full, timeout=240)
    print((o.read() + e.read()).decode(errors="replace"))
    ssh.close()

    print("=== NAV / LOAD PROBE ===")
    for path in [
        "https://crm.apexonepayments.com/admin/login",
        "https://crm.apexonepayments.com/portal/login",
        "https://crm.apexonepayments.com/",
    ]:
        status, ms = http_probe(path)
        print(f"{path} -> status={status} time_ms={ms:.0f}")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
