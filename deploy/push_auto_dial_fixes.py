#!/usr/bin/env python3
"""Deploy auto-dial UX fixes: no pad phone icon, mute while ringing, answering machine, lead lock."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

FILES = [
    "config/integrations.php",
    "resources/js/communications-auto-dial.js",
    "resources/js/communications-webphone.js",
    "resources/js/communications-dialer.js",
    "resources/views/communications/partials/dialer-form.blade.php",
    "resources/css/comm-hub-ghl-theme.css",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    upload_files(ssh, pairs, REMOTE_APP)
    print(
        sudo_run(
            ssh,
            f"""
cd {REMOTE_APP} && npm run build > /tmp/vite-auto-dial-fix.log 2>&1
tail -n 14 /tmp/vite-auto-dial-fix.log
echo BUILD:$?
chown -R www-data:www-data {REMOTE_APP}/public/build
cd {REMOTE_APP} && sudo -u www-data php artisan config:clear
cd {REMOTE_APP} && sudo -u www-data php artisan view:clear
grep -n "ch-dial-workspace--auto-mode\\|markAnsweringMachineAndHangup\\|Answering Machine\\|Mute is available while dialing" \\
  {REMOTE_APP}/resources/css/comm-hub-ghl-theme.css \\
  {REMOTE_APP}/resources/js/communications-webphone.js \\
  {REMOTE_APP}/resources/js/communications-auto-dial.js \\
  {REMOTE_APP}/config/integrations.php | head -30
""",
            check=False,
        )
    )
    ssh.close()
    print("Auto dial fixes deployed. Ctrl+F5 Communications dialer.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
