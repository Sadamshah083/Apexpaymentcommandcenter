#!/usr/bin/env python3
"""Deploy Call Notes download + pagination polish."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

FILES = [
    "app/Services/Communications/CallNotesHistoryService.php",
    "app/Http/Controllers/CallNotesController.php",
    "routes/web.php",
    "resources/views/communications/notes/index.blade.php",
    "resources/views/communications/notes/portal.blade.php",
    "resources/views/communications/notes/partials/panel.blade.php",
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
cd {REMOTE_APP} && npm run build > /tmp/vite-notes-download.log 2>&1
tail -n 14 /tmp/vite-notes-download.log
echo BUILD:$?
chown -R www-data:www-data {REMOTE_APP}/public/build
cd {REMOTE_APP} && sudo -u www-data php artisan view:clear
cd {REMOTE_APP} && sudo -u www-data php artisan route:clear
php -l {REMOTE_APP}/app/Http/Controllers/CallNotesController.php
grep -n "notes.download\\|Download notes\\|allNotesForAgent" \\
  {REMOTE_APP}/routes/web.php \\
  {REMOTE_APP}/app/Http/Controllers/CallNotesController.php \\
  {REMOTE_APP}/resources/views/communications/notes/partials/panel.blade.php | head -30
""",
            check=False,
        )
    )
    ssh.close()
    print("Call Notes download + pagination deployed. Ctrl+F5.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
