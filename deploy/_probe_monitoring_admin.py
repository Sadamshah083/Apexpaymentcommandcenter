#!/usr/bin/env python3
"""Probe production Call Monitoring admin filter + JS state."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

import os

os.environ.setdefault("DEPLOY_PASSWORD", "balitech1")

import deploy._ssh as m

m.HOST = "203.215.161.236"
m.USER = "ateg"
m.PASSWORD = "balitech1"
m.REMOTE_APP = "/var/www/apexone"

from deploy._ssh import REMOTE_APP, connect, sudo_run


def main() -> int:
    ssh = connect()
    print(
        sudo_run(
            ssh,
            f"""
echo '=== AgentPresenceService exclude ==='
grep -n "isExcludedFromMonitoring\\|EXCLUDED_ROLES\\|username\\|isAdmin\\|isSuperAdmin" \\
  {REMOTE_APP}/app/Services/Communications/AgentPresenceService.php | head -50
echo '=== CallMonitoring reject ==='
grep -n "rejectExcluded\\|isExcluded\\|monitoringExcluded\\|admin" \\
  {REMOTE_APP}/app/Services/Communications/CallMonitoringService.php | head -40
echo '=== call-monitoring.js fillTable ==='
grep -n "fillTable\\|innerHTML\\|patchRow\\|STICKY\\|appendChild" \\
  {REMOTE_APP}/resources/js/call-monitoring.js | head -40
echo '=== users named admin ==='
cd {REMOTE_APP} && sudo -u www-data php artisan tinker --execute="
\\$users = App\\Models\\User::query()->where(function(\\$q) {{
  \\$q->where('name', 'like', '%admin%')->orWhere('email', 'like', '%admin%');
}})->limit(20)->get(['id','name','email']);
foreach (\\$users as \\$u) {{
  echo \\$u->id.' | '.\\$u->name.' | '.\\$u->email.PHP_EOL;
  foreach (\\$u->workspaces as \\$w) {{
    echo '  ws'.\\$w->id.' role='.(\\$w->pivot->role ?? '').' status='.(\\$w->pivot->status ?? '').' ext='.(\\$w->pivot->morpheus_extension_num ?? '').PHP_EOL;
  }}
}}
"
""",
            check=False,
        )
    )
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
