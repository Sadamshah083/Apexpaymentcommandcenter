#!/usr/bin/env python3
"""Deploy: hide Super Admin from Call Monitoring Not-in-call board."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

FILES = [
    "app/Services/Communications/AgentPresenceService.php",
    "app/Services/Communications/CallMonitoringService.php",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    upload_files(ssh, pairs, REMOTE_APP)
    print(
        sudo_run(
            ssh,
            f"""
cd {REMOTE_APP} && sudo -u www-data php artisan cache:clear
cd {REMOTE_APP} && sudo -u www-data php artisan view:clear
grep -n "EXCLUDED_ROLES\\|isMonitorableRole\\|Super Admin and other non-agent" \\
  {REMOTE_APP}/app/Services/Communications/AgentPresenceService.php \\
  {REMOTE_APP}/app/Services/Communications/CallMonitoringService.php | head -25
""",
            check=False,
        )
    )
    ssh.close()
    print("Super Admin removed from Call Monitoring. Refresh Call Monitoring page.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
