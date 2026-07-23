#!/usr/bin/env python3
from __future__ import annotations

import subprocess
import sys
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]
NEW = {"host": "203.215.161.236", "user": "ateg", "password": "balitech1"}
REMOTE = "/var/www/apexone"
FILES = [
    "app/Services/MapsScraper/MapsScraperService.php",
    "app/Http/Controllers/MapsScraperController.php",
    "config/maps_scraper.php",
    "resources/views/maps-scraper/index.blade.php",
    "database/migrations/2026_07_23_180000_create_workflow_agent_access_table.php",
    "app/Models/WorkflowAgentAccess.php",
    "app/Models/Workflow.php",
    "app/Services/Communications/DialerImportedLeadsService.php",
    "app/Services/Workflow/WorkflowDashboardService.php",
    "app/Http/Controllers/WorkflowController.php",
    "app/Services/Workspace/WorkspaceSyncService.php",
    "routes/web.php",
    "resources/views/admin/dashboard/partials/imports-panel.blade.php",
    "resources/js/workspace-sync.js",
    "resources/css/app.css",
]


def main() -> int:
    subprocess.check_call([sys.executable, str(ROOT / "deploy" / "_append_disp_share_css.py")])

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
php artisan migrate --force --no-interaction
php artisan view:clear
php artisan route:clear
php artisan config:clear
npm run build --silent
grep -n "citiesForState\\|workflow_agent_access\\|Dispositions\\|import-disposition-btn\\|maps-scraper.cities" \\
  app/Services/MapsScraper/MapsScraperService.php \\
  routes/web.php \\
  resources/views/admin/dashboard/partials/imports-panel.blade.php \\
  resources/views/maps-scraper/index.blade.php | head -30
echo DONE_SCRAPER_ACCESS_DISPOSITIONS
"""
    print(sudo_run(ssh, inner))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
