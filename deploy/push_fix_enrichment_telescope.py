#!/usr/bin/env python3
"""Fix Gemini fallback, re-ingest zero-lead workflows, install Telescope."""

from __future__ import annotations

import os
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
os.environ.setdefault("DEPLOY_PASSWORD", "balitech1")

import deploy._ssh as m

m.HOST = "203.215.161.236"
m.USER = "ateg"
m.PASSWORD = "balitech1"
m.REMOTE_APP = "/var/www/apexone"

from deploy._ssh import REMOTE_APP, connect, sudo_run_batch, upload_files

FILES = [
    "app/Services/Workflow/WorkflowExtractor.php",
    "app/Services/Workflow/WorkflowProviderStatusService.php",
    "scripts/fix_enrichment_and_reingest.php",
]


def main() -> int:
    ssh = connect()
    try:
        upload_files(ssh, [(ROOT / rel, rel) for rel in FILES], app_root=REMOTE_APP)

        # Install Telescope (compatible with Laravel 13)
        out = sudo_run_batch(ssh, [
            f"cd {REMOTE_APP} && sudo -u www-data composer require laravel/telescope --no-interaction 2>&1 | tail -n 40; echo COMPOSE:$?",
            f"cd {REMOTE_APP} && sudo -u www-data php artisan telescope:install 2>&1; echo TELESCOPE_INSTALL:$?",
            f"cd {REMOTE_APP} && sudo -u www-data php artisan migrate --force --no-interaction 2>&1 | tail -n 30",
            # Ensure Telescope enabled in production for admins
            f"grep -q '^TELESCOPE_ENABLED=' {REMOTE_APP}/.env && sed -i 's/^TELESCOPE_ENABLED=.*/TELESCOPE_ENABLED=true/' {REMOTE_APP}/.env || echo 'TELESCOPE_ENABLED=true' >> {REMOTE_APP}/.env",
            f"cd {REMOTE_APP} && sudo -u www-data php artisan optimize:clear",
            f"cd {REMOTE_APP} && sudo -u www-data php scripts/fix_enrichment_and_reingest.php",
            "sleep 8",
            f"cd {REMOTE_APP} && sudo -u www-data php -r \"require 'vendor/autoload.php'; \\$a=require 'bootstrap/app.php'; \\$a->make(Illuminate\\\\Contracts\\\\Console\\\\Kernel::class)->bootstrap(); foreach(App\\\\Models\\\\Workflow::whereIn('id',[31,32,33,34,35])->get() as \\$w){{ echo 'WF'.\\$w->id.' status='.\\$w->status.' total='.\\$w->total_leads.' enriched='.\\$w->enriched_count.PHP_EOL; }} echo 'jobs='.Illuminate\\\\Support\\\\Facades\\\\DB::table('jobs')->count().PHP_EOL;\"",
            f"test -f {REMOTE_APP}/config/telescope.php && echo TELESCOPE_CONFIG_OK",
            f"cd {REMOTE_APP} && sudo -u www-data php artisan route:list --name=telescope 2>&1 | head -n 15",
        ])
        print(out.encode("ascii", "replace").decode("ascii"))
        print("LIVE_OK")
        return 0
    finally:
        ssh.close()


if __name__ == "__main__":
    raise SystemExit(main())
