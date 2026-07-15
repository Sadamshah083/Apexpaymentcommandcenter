#!/usr/bin/env python3
"""Deploy new dispositions + Call Notes sidebar page."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

FILES = [
    "config/integrations.php",
    "app/Services/Communications/DialerImportedLeadsService.php",
    "app/Services/Communications/CallNotesHistoryService.php",
    "app/Http/Controllers/CallNotesController.php",
    "routes/web.php",
    "resources/views/communications/partials/call-summary-modal.blade.php",
    "resources/views/communications/notes/index.blade.php",
    "resources/views/communications/notes/portal.blade.php",
    "resources/views/communications/notes/partials/panel.blade.php",
    "resources/views/layouts/partials/sidebar-nav-admin.blade.php",
    "resources/views/layouts/partials/sidebar-nav-portal.blade.php",
    "resources/views/layouts/partials/sidebar-icon.blade.php",
    "resources/js/communications-auto-dial.js",
    "resources/js/communications-webphone.js",
    "resources/css/app.css",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    upload_files(ssh, pairs, REMOTE_APP)
    print(
        sudo_run(
            ssh,
            f"""
cd {REMOTE_APP} && npm run build > /tmp/vite-dispo-notes.log 2>&1
tail -n 18 /tmp/vite-dispo-notes.log
echo BUILD:$?
chown -R www-data:www-data {REMOTE_APP}/public/build
cd {REMOTE_APP} && sudo -u www-data php artisan view:clear
cd {REMOTE_APP} && sudo -u www-data php artisan config:clear
cd {REMOTE_APP} && sudo -u www-data php artisan route:clear
php -l {REMOTE_APP}/app/Http/Controllers/CallNotesController.php
php -l {REMOTE_APP}/app/Services/Communications/CallNotesHistoryService.php
grep -n "Answer Machine\\|Call Notes\\|communications.notes" \\
  {REMOTE_APP}/config/integrations.php \\
  {REMOTE_APP}/resources/views/layouts/partials/sidebar-nav-admin.blade.php \\
  {REMOTE_APP}/routes/web.php | head -40
""",
            check=False,
        )
    )
    ssh.close()
    print("Dispositions + Call Notes deployed. Ctrl+F5.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
