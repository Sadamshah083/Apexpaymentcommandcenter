#!/usr/bin/env python3
"""Install and start Communications Hub monitoring on production."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, sudo_run_batch, upload_files

SERVICE = "apexone-comm-hub-monitor"
REMOTE_SERVICE = f"/etc/systemd/system/{SERVICE}.service"
REMOTE_TIMER = f"/etc/systemd/system/{SERVICE}.timer"


def main() -> int:
    ssh = connect()

    upload_files(
        ssh,
        [
            (ROOT / "scripts/comm_hub_monitor.php", "scripts/comm_hub_monitor.php"),
            (ROOT / f"deploy/{SERVICE}.service", f"deploy/{SERVICE}.service"),
            (ROOT / f"deploy/{SERVICE}.timer", f"deploy/{SERVICE}.timer"),
        ],
        app_root=REMOTE_APP,
    )

    sudo_run_batch(ssh, [
        f"cp {REMOTE_APP}/deploy/{SERVICE}.service {REMOTE_SERVICE}",
        f"cp {REMOTE_APP}/deploy/{SERVICE}.timer {REMOTE_TIMER}",
        "systemctl daemon-reload",
        f"systemctl enable {SERVICE}.timer",
        f"systemctl start {SERVICE}.timer",
        f"systemctl start {SERVICE}.service",
        f"touch {REMOTE_APP}/storage/logs/comm-hub-monitor.log",
        f"chown www-data:www-data {REMOTE_APP}/storage/logs/comm-hub-monitor.log",
    ])

    print("=== Timer status ===")
    print(sudo_run(ssh, f"systemctl status {SERVICE}.timer --no-pager", check=False))

    print("\n=== First monitor run ===")
    print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php scripts/comm_hub_monitor.php", check=False))

    print("\n=== Log tail ===")
    print(sudo_run(ssh, f"tail -20 {REMOTE_APP}/storage/logs/comm-hub-monitor.log", check=False))

    ssh.close()
    print("\nCommunications Hub monitoring is active (every 60s).")
    print(f"Log: {REMOTE_APP}/storage/logs/comm-hub-monitor.log")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
