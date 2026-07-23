#!/usr/bin/env python3
"""Deploy Call Monitoring WebSocket realtime to NEW and restart the WS bridge."""
from __future__ import annotations

import shlex
import sys
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]
NEW = {"host": "203.215.161.236", "user": "ateg", "password": "balitech1"}
REMOTE = "/var/www/apexone"
FILES = [
    "services/call-events-ws/server.mjs",
    "config/integrations.php",
    "app/Services/Communications/MorpheusCallEventService.php",
    "app/Services/Communications/AgentPresenceService.php",
    "app/Http/Controllers/CallMonitoringController.php",
    "resources/views/communications/monitoring/index.blade.php",
    "resources/views/communications/monitoring/portal.blade.php",
    "resources/views/communications/monitoring/partials/wallboard.blade.php",
    "resources/js/call-monitoring.js",
]


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(NEW["host"], username=NEW["user"], password=NEW["password"], timeout=40)

    sys.path.insert(0, str(ROOT))
    import deploy._ssh as ssh_mod

    ssh_mod.HOST = NEW["host"]
    ssh_mod.USER = NEW["user"]
    ssh_mod.PASSWORD = NEW["password"]
    ssh_mod.REMOTE_APP = REMOTE
    from deploy._ssh import upload_files

    pairs = [(ROOT / rel, rel) for rel in FILES]
    missing = [str(p) for p, _ in pairs if not p.is_file()]
    if missing:
        print("Missing:", *missing, sep="\n ")
        return 1

    print(f"Uploading {len(pairs)} files...")
    upload_files(ssh, pairs, app_root=REMOTE)

    files_chown = " ".join(f"{REMOTE}/{f}" for f in FILES)
    inner = f"""
set -e
cd {REMOTE}
chown www-data:www-data {files_chown}
# ensure service file points at repo server.mjs
if [ -f services/call-events-ws/apex-call-events-ws.service ]; then
  cp -f services/call-events-ws/apex-call-events-ws.service /etc/systemd/system/apex-call-events-ws.service || true
  systemctl daemon-reload || true
fi
systemctl restart apex-call-events-ws || systemctl restart call-events-ws || true
sleep 1
curl -fsS http://127.0.0.1:8787/health || true
echo
# smoke push-monitoring
curl -fsS -X POST http://127.0.0.1:8787/push-monitoring \
  -H 'Content-Type: application/json' \
  -d '{{"workspace_id":2,"reason":"deploy_smoke","version":1}}' || true
echo
sudo -u www-data php artisan config:clear
sudo -u www-data php artisan view:clear
sudo -u www-data npm run build > /tmp/vite-mon-ws.log 2>&1 || true
tail -n 15 /tmp/vite-mon-ws.log
chown -R www-data:www-data public/build
systemctl reload php8.3-fpm 2>/dev/null || systemctl reload php8.2-fpm 2>/dev/null || true
grep -n 'channel=monitoring\\|connectWebSocket\\|push-monitoring' resources/js/call-monitoring.js services/call-events-ws/server.mjs app/Services/Communications/MorpheusCallEventService.php | head -20
ls -lt public/build/assets/call-monitoring-*.js | head -2
echo DONE_WS_MONITORING
"""
    cmd = f"echo {shlex.quote(NEW['password'])} | sudo -S -p '' bash -lc {shlex.quote(inner)}"
    _, o, e = ssh.exec_command(cmd, timeout=360)
    print((o.read() + e.read()).decode(errors="replace")[-12000:])
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
