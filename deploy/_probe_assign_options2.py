#!/usr/bin/env python3
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
    out = sudo_run_batch(ssh, [
        f"grep -c assign-leads-team-pick {m.REMOTE_APP}/resources/css/app.css || true",
        f"ls {m.REMOTE_APP}/public/build/assets/app-*.css | head -1 | xargs -I{{}} sh -c 'grep -c assign-leads-team-pick {{}} || true'",
        f"cd {m.REMOTE_APP} && sudo -u www-data php artisan tinker --execute=\""
        "\\$ws=App\\\\Models\\\\Workspace::query()->orderBy('id')->first(); "
        "\\$leads=App\\\\Support\\\\WorkflowAssignmentRoles::assignableTeamLeadsFor(\\$ws); "
        "echo 'count='.\\$leads->count(); "
        "echo PHP_EOL; "
        "foreach(\\$leads as \\$u){ echo \\$u->id.' '.\\$u->name.' '.(\\$u->pivot->role??'?').PHP_EOL; }"
        "\" 2>/dev/null | head -40",
        "true",
    ])
    # Keep output short for local console
    print(out[:4000])
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
