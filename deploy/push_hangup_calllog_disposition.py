#!/usr/bin/env python3
"""Deploy: hangup→call-log move, no double disposition, clear Dialing…"""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

FILES = [
    "resources/js/communications-auto-dial.js",
    "resources/js/communications-webphone.js",
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
cd {REMOTE_APP} && npm run build > /tmp/vite-hangup-calllog.log 2>&1
tail -n 18 /tmp/vite-hangup-calllog.log
echo BUILD:$?
php artisan view:clear >/dev/null 2>&1 || true
php artisan cache:clear >/dev/null 2>&1 || true
chown -R www-data:www-data {REMOTE_APP}/public/build
grep -n "relocateDialingLeadToCallLog\\|markPendingDispositionLeadRows\\|afterResponse\\|restoreDialerAfterCall({{ force: true }})" \\
  {REMOTE_APP}/resources/js/communications-auto-dial.js \\
  {REMOTE_APP}/resources/js/communications-webphone.js \\
  {REMOTE_APP}/app/Http/Controllers/CommunicationsHubController.php | head -40
""",
            check=False,
        )
    )
    ssh.close()
    print("Hangup/call-log/disposition fix deployed. Hard refresh (Ctrl+F5) dialer.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
