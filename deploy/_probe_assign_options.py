#!/usr/bin/env python3
"""Check assign CSS + option counts on production."""

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
        f"grep -n 'assign-leads-team-pick' {m.REMOTE_APP}/resources/css/app.css | head",
        f"grep -n 'assign-leads-team-pick' {m.REMOTE_APP}/public/build/assets/app-*.css | head",
        # count options in compiled view cache if any / probe DB roles
        f"cd {m.REMOTE_APP} && sudo -u www-data php -r \""
        "require 'vendor/autoload.php'; "
        "\\$app=require 'bootstrap/app.php'; "
        "\\$app->make(Illuminate\\\\Contracts\\\\Console\\\\Kernel::class)->bootstrap(); "
        "\\$ws=App\\\\Models\\\\Workspace::query()->orderBy('id')->first(); "
        "if(!\\$ws){echo 'no workspace'; exit;} "
        "\\$leads=App\\\\Support\\\\WorkflowAssignmentRoles::assignableTeamLeadsFor(\\$ws); "
        "echo 'leads='.\\$leads->count().PHP_EOL; "
        "foreach(\\$leads as \\$u){ echo \\$u->id.'|'.\\$u->name.'|'.(\\$u->pivot->role??'?').PHP_EOL; } "
        "\"",
        "true",
    ]))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
