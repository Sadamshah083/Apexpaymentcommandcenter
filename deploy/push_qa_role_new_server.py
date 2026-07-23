#!/usr/bin/env python3
"""Deploy QA role / call-log ACL to NEW (domain) server."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

import deploy._ssh as m

m.HOST = "203.215.161.236"
m.USER = "ateg"
m.PASSWORD = "balitech1"
m.REMOTE_APP = "/var/www/apexone"

from deploy._ssh import REMOTE_APP, connect, sudo_run_batch, upload_files

FILES = [
    "config/sales_ops.php",
    "config/portal_modules.php",
    "app/Support/SalesOps.php",
    "app/Support/MemberModuleAccess.php",
    "app/Models/User.php",
    "app/Services/Communications/CommunicationsAccessService.php",
    "app/Services/Communications/CommunicationsInboxService.php",
    "app/Services/Communications/AgentPresenceService.php",
    "app/Http/Controllers/CallNotesController.php",
    "app/Http/Controllers/AgentStatusReportController.php",
    "app/Http/Controllers/CommunicationsHubController.php",
    "resources/views/layouts/partials/sidebar-nav-portal.blade.php",
    "resources/views/layouts/partials/sidebar-nav-admin.blade.php",
    "scripts/upsert_team_accounts.php",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES]
    missing = [str(p) for p, _ in pairs if not p.is_file()]
    if missing:
        print("Missing:")
        for path in missing:
            print(f"  - {path}")
        return 1

    ssh = connect()
    print("1) Upload to NEW server...")
    upload_files(ssh, pairs, app_root=REMOTE_APP)

    print("2) Clear caches + upsert QA role...")
    print(sudo_run_batch(ssh, [
        f"cd {REMOTE_APP} && sudo -u www-data php artisan config:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan cache:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan route:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php scripts/upsert_team_accounts.php | tail -n 20",
    ], check=False))

    ssh.close()
    print("Done on NEW server (crm.apexonepayments.com).")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
