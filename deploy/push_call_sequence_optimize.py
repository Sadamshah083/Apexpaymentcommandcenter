#!/usr/bin/env python3
"""Deploy WS-before-originate gate, presence/live dedupe, append-only dispositions."""
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

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

FILES = [
    "database/migrations/2026_07_21_060000_create_lead_dispositions_table.php",
    "app/Models/LeadDisposition.php",
    "app/Services/Communications/CommunicationsCallHistoryService.php",
    "app/Http/Controllers/CommunicationsHubController.php",
    "resources/js/communications-webphone.js",
    "resources/js/communications-dialer.js",
    "resources/js/communications-auto-dial.js",
    "resources/js/call-monitoring.js",
    "resources/js/workspace-sync.js",
]


def main() -> int:
    ssh = connect()
    try:
        upload_files(ssh, [(ROOT / f, f) for f in FILES], REMOTE_APP)
        out = sudo_run(
            ssh,
            f"""
cd {REMOTE_APP}
php -l app/Models/LeadDisposition.php
php -l app/Services/Communications/CommunicationsCallHistoryService.php
php -l app/Http/Controllers/CommunicationsHubController.php
php artisan migrate --force --no-interaction
php artisan view:clear
php artisan config:clear
npm run build > /tmp/vite-call-seq.log 2>&1
echo BUILD:$?
tail -n 14 /tmp/vite-call-seq.log | tr -cd '\\11\\12\\15\\40-\\176'
grep -n 'assertTransportForOriginate\\|bootPresenceOnce\\|HANGUP_POLL_DEBOUNCE\\|appendLeadDisposition\\|lead_dispositions' \\
  resources/js/communications-webphone.js \\
  resources/js/communications-auto-dial.js \\
  resources/js/call-monitoring.js \\
  app/Services/Communications/CommunicationsCallHistoryService.php 2>/dev/null | head -25
php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
echo Schema::hasTable("lead_dispositions") ? "lead_dispositions=OK\\n" : "lead_dispositions=MISSING\\n";
'
ls -lt public/build/assets/communications-*.js public/build/assets/call-monitoring-*.js 2>/dev/null | head -8
chown -R www-data:www-data storage bootstrap/cache public/build 2>/dev/null || true
""",
        )
        print(out)
        print("Deployed call-sequence optimization. Hard refresh Ctrl+F5 on dialer.")
    finally:
        ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
