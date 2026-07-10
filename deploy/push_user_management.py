#!/usr/bin/env python3
"""Deploy user management UI files and rebuild frontend."""

from __future__ import annotations

import shlex
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, restart_queue_workers, sudo_run, sudo_run_batch, upload_files

FILES = [
    "resources/views/workflows/workspaces.blade.php",
    "resources/views/workflows/partials/add-member-modal.blade.php",
    "resources/views/workflows/partials/member-row.blade.php",
    "resources/views/workflows/partials/member-module-access.blade.php",
    "resources/views/workflows/partials/member-module-access-fields.blade.php",
    "resources/css/app.css",
    "resources/js/member-management.js",
    "resources/js/workspace-admin.js",
    "app/Http/Controllers/WorkspaceMemberController.php",
    "app/Http/Controllers/WorkspaceAuthController.php",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    print(f"Uploading {len(pairs)} files...")
    upload_files(ssh, pairs, app_root=REMOTE_APP)
    print("Building frontend assets...")
    sudo_run_batch(ssh, [
        f"cd {REMOTE_APP} && npm ci --ignore-scripts",
        f"cd {REMOTE_APP} && npm run build",
        f"chown -R www-data:www-data {REMOTE_APP}/public/build",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear",
    ])
    try:
        restart_queue_workers(ssh)
    except RuntimeError as exc:
        print(f"Warning: queue restart skipped ({exc})")
    print(sudo_run(ssh, f"curl -fsS http://203.215.160.44/admin/login -o /dev/null -w '%{{http_code}}'"))
    ssh.close()
    print("User management UI deployed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
