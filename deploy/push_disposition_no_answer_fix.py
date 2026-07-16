#!/usr/bin/env python3
"""Fix disposition display: keep agent 'No Answer' and deploy tests."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

FILES = [
    "app/Http/Controllers/CommunicationsHubController.php",
    "app/Services/Communications/CommunicationsInboxService.php",
    "resources/views/communications/partials/call-log-row.blade.php",
    "resources/js/communications-auto-dial.js",
    "resources/js/communications-dialer.js",
    "tests/Feature/DialerDispositionTest.php",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    upload_files(ssh, pairs, REMOTE_APP)
    print(
        sudo_run(
            ssh,
            f"""
cd {REMOTE_APP}
php artisan view:clear >/dev/null 2>&1 || true
php artisan config:clear >/dev/null 2>&1 || true
npm run build > /tmp/vite-disposition-fix.log 2>&1
tail -n 16 /tmp/vite-disposition-fix.log
echo BUILD:$?
chown -R www-data:www-data {REMOTE_APP}/public/build
# Prove the skip-list no longer hides agent disposition "No Answer"
! grep -n "'No Answer'" {REMOTE_APP}/resources/views/communications/partials/call-log-row.blade.php | grep -i skip || true
grep -n "no answer" {REMOTE_APP}/resources/js/communications-dialer.js {REMOTE_APP}/resources/js/communications-auto-dial.js {REMOTE_APP}/app/Services/Communications/CommunicationsInboxService.php | head -20
./vendor/bin/phpunit --filter=DialerDispositionTest 2>&1 | tail -n 80
""",
            check=False,
        )
    )
    ssh.close()
    print("Disposition No Answer fix deployed. Hard refresh (Ctrl+F5).")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
