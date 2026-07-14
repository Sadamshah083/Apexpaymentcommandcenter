#!/usr/bin/env python3
"""Deploy: remove lead from imported list on disposition, prepend call log, stay on leads tab."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

FILES = [
    "resources/js/communications-auto-dial.js",
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
cd {REMOTE_APP} && npm run build > /tmp/vite-disposition-leads.log 2>&1
tail -n 14 /tmp/vite-disposition-leads.log
echo BUILD:$?
chown -R www-data:www-data {REMOTE_APP}/public/build
cd {REMOTE_APP} && sudo -u www-data php artisan view:clear
grep -n "applyDispositionSideEffects\\|phonesMatch\\|never jump to Call logs\\|switchToLeadsTab" \\
  {REMOTE_APP}/resources/js/communications-auto-dial.js | head -25
""",
            check=False,
        )
    )
    ssh.close()
    print("Disposition lead-remove + call-log fix deployed. Ctrl+F5.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
