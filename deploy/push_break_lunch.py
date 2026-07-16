#!/usr/bin/env python3
"""Deploy Break In / Lunch APIs + Call Monitoring + dialer controls."""

from __future__ import annotations

import os
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

os.environ.setdefault("DEPLOY_PASSWORD", "SadamShah123")
import deploy._ssh as ssh_mod

ssh_mod.PASSWORD = os.environ.get("DEPLOY_PASSWORD", "") or ssh_mod.PASSWORD
from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

FILES = [
    "database/migrations/2026_07_15_225000_create_agent_activity_sessions_table.php",
    "app/Models/AgentActivitySession.php",
    "app/Services/Communications/AgentBreakService.php",
    "app/Services/Communications/AgentPresenceService.php",
    "app/Services/Communications/CallMonitoringService.php",
    "app/Services/Communications/CommunicationsAccessService.php",
    "app/Http/Controllers/CallMonitoringController.php",
    "routes/web.php",
    "resources/views/communications/partials/center-dialer-hub.blade.php",
    "resources/views/communications/monitoring/partials/wallboard.blade.php",
    "resources/views/communications/monitoring/partials/row.blade.php",
    "resources/js/communications-auto-dial.js",
    "resources/js/call-monitoring.js",
    "resources/js/app.js",
    "resources/css/app.css",
    "resources/css/comm-hub-ui-polish.css",
]

REMOTE = r"""
cd /var/www/apexone
php artisan migrate --force --no-interaction > /tmp/break-lunch-migrate.log 2>&1
echo MIGRATE:$?
tail -n 20 /tmp/break-lunch-migrate.log
npm run build > /tmp/vite-break-lunch.log 2>&1
echo BUILD:$?
tail -n 12 /tmp/vite-break-lunch.log
php artisan view:clear >/dev/null 2>&1 || true
php artisan route:clear >/dev/null 2>&1 || true
php artisan config:clear >/dev/null 2>&1 || true
chown -R www-data:www-data /var/www/apexone/public/build
php artisan route:list --name=monitoring.break 2>/dev/null | head -n 20
python3 -c "
from pathlib import Path
assets = Path('public/build/assets')
for pat in ('communications-auto-dial-*.js', 'call-monitoring-*.js'):
    p = next(assets.glob(pat), None)
    if not p:
        print(pat, 'MISSING')
        continue
    t = p.read_text(errors='replace')
    print(p.name)
    print(' initAgentBreakControls', t.count('initAgentBreakControls'))
    print(' break_status', t.count('break_status'))
    print(' is-break', t.count('is-break'))
    print(' stickyBreak', t.count('stickyBreak'))
"
"""


def main() -> int:
    missing = [rel for rel in FILES if not (ROOT / rel).is_file()]
    if missing:
        print("Missing files:", missing)
        return 1
    pairs = [(ROOT / rel, rel) for rel in FILES]
    ssh = connect()
    upload_files(ssh, pairs, REMOTE_APP)
    print(sudo_run(ssh, REMOTE, check=False).encode("ascii", "replace").decode("ascii"))
    ssh.close()
    print("Break/Lunch deployed. Hard refresh Ctrl+Shift+R on dialer + Call Monitoring.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
