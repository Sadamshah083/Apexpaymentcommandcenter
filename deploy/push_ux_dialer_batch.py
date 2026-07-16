#!/usr/bin/env python3
"""Deploy dialer/UX batch: auto-dial 6s+stop-1, disposition, UM/campaigns tables, pagination, CRM create removed."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, restart_queue_workers, sudo_run, sudo_run_batch, upload_files

FILES = [
    "resources/js/communications-auto-dial.js",
    "resources/js/pagination-preserve.js",
    "resources/js/app.js",
    "resources/css/app.css",
    "resources/css/comm-hub-ui-polish.css",
    "resources/views/admin/dashboard/partials/campaigns-panel.blade.php",
    "resources/views/admin/dashboard/index.blade.php",
    "resources/views/layouts/partials/sidebar-nav-admin.blade.php",
    "resources/views/communications/partials/call-summary-modal.blade.php",
    "resources/views/components/pagination.blade.php",
    "resources/views/vendor/pagination/tailwind.blade.php",
    "resources/views/crm/index.blade.php",
    "app/Http/Controllers/CrmCampaignController.php",
    "routes/web.php",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    print(f"Uploading {len(pairs)} files...")
    upload_files(ssh, pairs, app_root=REMOTE_APP)

    # Remove deleted create view if present remotely.
    sudo_run(ssh, f"rm -f {REMOTE_APP}/resources/views/crm/create.blade.php", check=False)

    print("Building assets + clearing caches...")
    print(
        sudo_run(
            ssh,
            f"cd {REMOTE_APP} && "
            "rm -rf node_modules/.vite node_modules/.vite-temp && "
            "npm run build > /tmp/apex_ux_build.log 2>&1; "
            "echo EXIT:$? >> /tmp/apex_ux_build.log; "
            f"chown -R www-data:www-data {REMOTE_APP}/public/build; "
            "sudo -u www-data php artisan view:clear; "
            "sudo -u www-data php artisan route:clear; "
            "sudo -u www-data php artisan optimize:clear; "
            "tail -n 20 /tmp/apex_ux_build.log",
        )
    )

    try:
        restart_queue_workers(ssh)
    except RuntimeError as exc:
        print(f"Warning: queue restart skipped ({exc})")

    print(sudo_run(ssh, "curl -fsS -o /dev/null -w '%{http_code}' https://crm.apexonepayments.com/admin/login", check=False))
    ssh.close()
    print("UX + dialer batch deployed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
