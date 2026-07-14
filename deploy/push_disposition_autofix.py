#!/usr/bin/env python3
"""Deploy auto-dial disposition fixes: block queue while summary open, toast feedback, status maps."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

FILES = [
    "resources/js/communications-auto-dial.js",
    "resources/js/communications-webphone.js",
    "resources/css/comm-hub-ghl-theme.css",
    "app/Services/Communications/DialerImportedLeadsService.php",
    "app/Http/Controllers/CommunicationsHubController.php",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    upload_files(ssh, pairs, REMOTE_APP)
    print(
        sudo_run(
            ssh,
            f"""
cd {REMOTE_APP} && npm run build > /tmp/vite-disposition-fix.log 2>&1
tail -n 16 /tmp/vite-disposition-fix.log
echo BUILD:$?
chown -R www-data:www-data {REMOTE_APP}/public/build
cd {REMOTE_APP} && sudo -u www-data php artisan view:clear
cd {REMOTE_APP} && sudo -u www-data php artisan config:clear
grep -n "isDispositionBlocking\\|suggestedDispositionFromResult\\|Set disposition to continue\\|Save & Next\\|answering machine" \\
  {REMOTE_APP}/resources/js/communications-auto-dial.js \\
  {REMOTE_APP}/app/Services/Communications/DialerImportedLeadsService.php | head -40
""",
            check=False,
        )
    )
    ssh.close()
    print("Disposition autofix deployed. Ctrl+F5 Communications dialer.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
