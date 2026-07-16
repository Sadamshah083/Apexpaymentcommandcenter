#!/usr/bin/env python3
"""Deploy auto-dial live UI sync + configurable next-call delay."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

FILES = [
    "config/integrations.php",
    "app/Http/Controllers/CommunicationsHubController.php",
    "resources/views/communications/partials/center-dialer-hub.blade.php",
    "resources/views/communications/partials/call-summary-modal.blade.php",
    "resources/js/communications-auto-dial.js",
    "resources/js/communications-webphone.js",
    "resources/css/comm-hub-ui-polish.css",
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
php artisan config:clear >/dev/null 2>&1 || true
php artisan view:clear >/dev/null 2>&1 || true
npm run build > /tmp/vite-autodial-ui-delay.log 2>&1
tail -n 18 /tmp/vite-autodial-ui-delay.log
echo BUILD:$?
chown -R www-data:www-data {REMOTE_APP}/public/build
grep -n "next_call_delay_sec\\|resolveNextCallDelayMs\\|syncAutoDialLiveCallUi\\|is-connected-call" \\
  {REMOTE_APP}/config/integrations.php \\
  {REMOTE_APP}/app/Http/Controllers/CommunicationsHubController.php \\
  {REMOTE_APP}/resources/js/communications-auto-dial.js \\
  {REMOTE_APP}/resources/views/communications/partials/center-dialer-hub.blade.php | head -40
./vendor/bin/phpunit --filter=DialerDispositionTest 2>&1 | tail -n 30
""",
            check=False,
        )
    )
    ssh.close()
    print("Auto-dial UI sync + configurable delay deployed. Ctrl+F5.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
