#!/usr/bin/env python3
"""Deploy Owner Hangup disposition option."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

FILES = [
    "config/integrations.php",
    "resources/views/communications/partials/call-summary-modal.blade.php",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES]
    ssh = connect()
    upload_files(ssh, pairs, REMOTE_APP)
    print(
        sudo_run(
            ssh,
            f"""
cd {REMOTE_APP} && php artisan config:clear >/dev/null 2>&1 || true
cd {REMOTE_APP} && php artisan view:clear >/dev/null 2>&1 || true
grep -n "Owner Hangup" {REMOTE_APP}/config/integrations.php \\
  {REMOTE_APP}/resources/views/communications/partials/call-summary-modal.blade.php
""",
            check=False,
        )
    )
    ssh.close()
    print("Owner Hangup disposition deployed. Hard refresh dialer.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
