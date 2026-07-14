#!/usr/bin/env python3
"""Deploy Team column (agent/team-lead assignment) on User Management."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

FILES = [
    "database/migrations/2026_07_14_020000_add_team_lead_user_id_to_workspace_user.php",
    "app/Models/Workspace.php",
    "app/Models/User.php",
    "app/Support/SalesOps.php",
    "app/Services/Workspace/WorkspaceMemberService.php",
    "app/Http/Controllers/WorkspaceMemberController.php",
    "app/Http/Controllers/WorkflowController.php",
    "app/Services/Pipeline/SetterDistributionService.php",
    "routes/web.php",
    "resources/views/workflows/workspaces.blade.php",
    "resources/views/workflows/partials/member-row.blade.php",
    "resources/js/member-management.js",
    "resources/css/app.css",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    missing = [rel for rel in FILES if not (ROOT / rel).is_file()]
    if missing:
        print("Missing:", ", ".join(missing))
        return 1

    ssh = connect()
    print(f"Uploading {len(pairs)} files...")
    upload_files(ssh, pairs, REMOTE_APP)
    print(
        sudo_run(
            ssh,
            f"""
cd {REMOTE_APP} && sudo -u www-data php artisan migrate --force --no-interaction
cd {REMOTE_APP} && npm run build > /tmp/vite-team-col.log 2>&1
tail -n 12 /tmp/vite-team-col.log
echo BUILD:$?
chown -R www-data:www-data {REMOTE_APP}/public/build
cd {REMOTE_APP} && sudo -u www-data php artisan route:clear
cd {REMOTE_APP} && sudo -u www-data php artisan view:clear
cd {REMOTE_APP} && sudo -u www-data php artisan cache:clear
cd {REMOTE_APP} && sudo -u www-data php artisan route:list --name=members.team-lead 2>&1 | head -8
php -r "require '{REMOTE_APP}/vendor/autoload.php'; echo 'ok';"
grep -n "team_lead_user_id\\|col-team\\|updateMemberTeamLead" \\
  {REMOTE_APP}/resources/views/workflows/partials/member-row.blade.php \\
  {REMOTE_APP}/app/Services/Workspace/WorkspaceMemberService.php \\
  {REMOTE_APP}/routes/web.php | head -20
""",
            check=False,
        )
    )
    ssh.close()
    print("Team assignment column deployed. Hard-refresh /admin/workspaces.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
