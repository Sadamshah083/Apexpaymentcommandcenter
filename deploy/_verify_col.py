#!/usr/bin/env python3
from __future__ import annotations

import os
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
os.environ.setdefault("DEPLOY_PASSWORD", "balitech1")

import deploy._ssh as ssh_mod

ssh_mod.HOST = "203.215.161.236"
ssh_mod.USER = "ateg"
ssh_mod.PASSWORD = "balitech1"
ssh_mod.REMOTE_APP = "/var/www/apexone"

from deploy._ssh import REMOTE_APP, connect, sudo_run


def main() -> None:
    ssh = connect()
    try:
        cmds = [
            f"ls -la {REMOTE_APP}/database/migrations/2026_07_21_220000_add_agent_restricted_to_workflows_table.php",
            f"cd {REMOTE_APP} && php artisan migrate:status 2>&1 | grep -i agent_restricted || true",
            (
                "cd /var/www/apexone && php -r \""
                "require 'vendor/autoload.php'; "
                "\\$app=require 'bootstrap/app.php'; "
                "\\$app->make(Illuminate\\\\Contracts\\\\Console\\\\Kernel::class)->bootstrap(); "
                "echo Illuminate\\\\Support\\\\Facades\\\\Schema::hasColumn('workflows','agent_restricted') ? 'col_ok' : 'col_missing';"
                "\""
            ),
            f"grep -n agent_restricted {REMOTE_APP}/app/Models/Workflow.php | head -5",
            f"grep -n import-restrict-btn {REMOTE_APP}/resources/views/admin/dashboard/partials/imports-panel.blade.php | head -5",
            f"systemctl reload php8.3-fpm 2>/dev/null || systemctl reload php-fpm 2>/dev/null || true",
        ]
        for cmd in cmds:
            print("====")
            print(sudo_run(ssh, cmd, check=False))
            print()
    finally:
        ssh.close()


if __name__ == "__main__":
    main()
