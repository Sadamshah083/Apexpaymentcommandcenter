#!/usr/bin/env python3
"""Deploy import-delete stream teardown fix."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, restart_queue_workers, sudo_run, upload_files

FILES = [
    "resources/js/workspace-sync.js",
    "resources/js/app.js",
    "resources/views/workflows/partials/import-modals.blade.php",
    "app/Services/Workflow/WorkflowService.php",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    print(f"Uploading {len(pairs)} files...")
    upload_files(ssh, pairs, app_root=REMOTE_APP)

    cmd = (
        f"cd {REMOTE_APP} && "
        "rm -rf node_modules/.vite node_modules/.vite-temp && "
        "npm run build > /tmp/apex_vite_build.log 2>&1; "
        "echo EXIT:$? >> /tmp/apex_vite_build.log; "
        f"chown -R www-data:www-data {REMOTE_APP}/public/build; "
        "tail -n 25 /tmp/apex_vite_build.log"
    )
    print(sudo_run(ssh, cmd))
    print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear", check=False))

    # Confirm new teardown markers are in built assets
    print(
        sudo_run(
            ssh,
            f"cd {REMOTE_APP} && "
            "grep -l 'workspace:teardown-request' public/build/assets/*.js | head -3; "
            "grep -l 'import-delete-form' public/build/assets/*.js resources/views/workflows/partials/import-modals.blade.php | head -5; "
            "grep -n 'Deleting' resources/views/workflows/partials/import-modals.blade.php | head -3",
            check=False,
        )
    )

    try:
        restart_queue_workers(ssh)
    except RuntimeError as exc:
        print(f"Warning: queue restart skipped ({exc})")

    ssh.close()
    print("Delete-stream teardown deployed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
