#!/usr/bin/env python3
"""Verify assign modal Team Setter/Closer on production."""

from __future__ import annotations

import os
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
os.environ.setdefault("DEPLOY_PASSWORD", "balitech1")

import deploy._ssh as m

m.HOST = "203.215.161.236"
m.USER = "ateg"
m.PASSWORD = "balitech1"
m.REMOTE_APP = "/var/www/apexone"

from deploy._ssh import connect, sudo_run_batch


def main() -> int:
    ssh = connect()
    print(sudo_run_batch(ssh, [
        "grep -n 'Team Setter\\|Team Closer\\|import-assign-team-setter\\|import-assign-team-closer\\|team_lead_id' "
        f"{m.REMOTE_APP}/resources/views/workflows/partials/import-modals.blade.php | head -40",
        "grep -n 'All call logs\\|Call Monitoring\\|sidebar-live-chip' "
        f"{m.REMOTE_APP}/resources/views/layouts/partials/sidebar-nav-admin.blade.php | head -30",
        "true",
    ]))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
