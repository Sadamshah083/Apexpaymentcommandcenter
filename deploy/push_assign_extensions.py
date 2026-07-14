#!/usr/bin/env python3
"""Assign team extensions and deploy dialer fallback fix."""

from __future__ import annotations

import shlex
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import PASSWORD, REMOTE_APP, connect, sudo_run, sudo_run_batch, upload_files

FILES = [
    "app/Services/Communications/CommunicationsAgentService.php",
    "scripts/assign_team_extensions.php",
    "resources/views/communications/partials/hub-tabs.blade.php",
    "resources/views/components/communications/list-pagination.blade.php",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    print(f"Uploading {len(pairs)} files...")
    upload_files(ssh, pairs, app_root=REMOTE_APP)

    sudo_run_batch(
        ssh,
        [
            f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear",
            f"cd {REMOTE_APP} && sudo -u www-data php artisan config:clear",
            f"cd {REMOTE_APP} && sudo -u www-data php artisan config:cache",
        ],
    )

    print("Assigning extensions...")
    print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php scripts/assign_team_extensions.php"))

    print("Final extension map (new team):")
    print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php scripts/_list_extensions.php"))

    ssh.close()
    print("Done.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
