#!/usr/bin/env python3
"""Deploy Call Monitoring Not-in-call idle timer + Auto/Manual dial presence."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

FILES = [
    "app/Services/Communications/AgentPresenceService.php",
    "app/Services/Communications/CallMonitoringService.php",
    "app/Http/Controllers/CallMonitoringController.php",
    "app/Http/Controllers/MorpheusHubController.php",
    "routes/web.php",
    "resources/js/call-monitoring.js",
    "resources/js/communications-auto-dial.js",
    "resources/js/app.js",
    "resources/css/app.css",
    "resources/views/communications/monitoring/partials/wallboard.blade.php",
    "resources/views/communications/monitoring/partials/table-section.blade.php",
    "resources/views/communications/monitoring/partials/row.blade.php",
    "resources/views/communications/partials/center-dialer-hub.blade.php",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    missing = [rel for rel in FILES if not (ROOT / rel).is_file()]
    if missing:
        print("Missing files:", ", ".join(missing))
        return 1

    ssh = connect()
    print(f"Uploading {len(pairs)} files...")
    upload_files(ssh, pairs, app_root=REMOTE_APP)
    print(
        sudo_run(
            ssh,
            f"""
cd {REMOTE_APP} && npm run build > /tmp/vite-build-presence.log 2>&1
tail -n 20 /tmp/vite-build-presence.log
echo BUILD:$?
chown -R www-data:www-data {REMOTE_APP}/public/build
cd {REMOTE_APP} && sudo -u www-data php artisan route:clear
cd {REMOTE_APP} && sudo -u www-data php artisan view:clear
cd {REMOTE_APP} && sudo -u www-data php artisan cache:clear
cd {REMOTE_APP} && sudo -u www-data php artisan route:list --name=monitoring.presence 2>&1 | head -10
test -f {REMOTE_APP}/app/Services/Communications/AgentPresenceService.php && echo PRESENCE_SERVICE=ok
grep -n "not_in_call\\|AgentPresenceService\\|is-idle\\|sendPresenceHeartbeat\\|data-presence-url" \\
  {REMOTE_APP}/app/Services/Communications/CallMonitoringService.php \\
  {REMOTE_APP}/resources/js/call-monitoring.js \\
  {REMOTE_APP}/resources/js/communications-auto-dial.js \\
  {REMOTE_APP}/resources/views/communications/partials/center-dialer-hub.blade.php \\
  {REMOTE_APP}/resources/views/communications/monitoring/partials/wallboard.blade.php | head -40
""",
            check=False,
        )
    )
    ssh.close()
    print("Not-in-call presence monitoring deployed. Hard-refresh (Ctrl+F5) Call Monitoring + Dialer.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
