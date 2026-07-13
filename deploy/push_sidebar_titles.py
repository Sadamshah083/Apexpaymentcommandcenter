#!/usr/bin/env python3
"""Hotfix: hide sidebar section titles."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files  # noqa: E402

FILES = [
    "resources/views/components/sidebar/section.blade.php",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES]
    ssh = connect()
    upload_files(ssh, pairs, app_root=REMOTE_APP)
    print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear"))
    print("health", sudo_run(ssh, "curl -fsS -o /dev/null -w '%{http_code}' https://crm.apexonepayments.com/up"))
    ssh.close()
    print("Sidebar section titles removed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
