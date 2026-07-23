#!/usr/bin/env python3
"""Smoke-check Active Leads file dropdown on prod."""

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

from deploy._ssh import REMOTE_APP, connect, sudo_run_batch


def main() -> int:
    ssh = connect()
    print(sudo_run_batch(ssh, [
        f"grep -n 'Uploaded file\\|All uploaded files\\|active-leads-file\\|uploadedWorkflows' {REMOTE_APP}/resources/views/admin/dashboard/partials/imports-panel.blade.php | head -n 30",
        f"grep -n 'uploadedWorkflows\\|workflow_id' {REMOTE_APP}/app/Services/Workflow/WorkflowDashboardService.php | head -n 20",
        f"ls -1 {REMOTE_APP}/public/build/assets/pretty-select*.js",
        f"ls -1 {REMOTE_APP}/public/build/assets/app-*.css | tail -n 3",
    ]))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
