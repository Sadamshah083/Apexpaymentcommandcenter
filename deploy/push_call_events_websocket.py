#!/usr/bin/env python3
"""Deploy webhook → WebSocket call-events bridge (replaces dialer HTTP polling)."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

FILES = [
    "resources/js/communications-webphone.js",
    "resources/js/communications-auto-dial.js",
    "resources/js/call-monitoring.js",
    "resources/views/communications/partials/webphone-panel.blade.php",
    "app/Services/Communications/MorpheusCallEventService.php",
    "app/Http/Controllers/MorpheusHubController.php",
    "config/integrations.php",
    "services/call-events-ws/server.mjs",
    "services/call-events-ws/package.json",
    "services/call-events-ws/apex-call-events-ws.service",
    "deploy/_patch_nginx_call_ws.py",
]

NGINX_BLOCK = r"""
    # Dialer call-events WebSocket (webhook push → browser).
    location /communications-ws/ {
        proxy_pass http://127.0.0.1:8787/;
        proxy_http_version 1.1;
        proxy_buffering off;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection $connection_upgrade;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 86400s;
        proxy_send_timeout 86400s;
    }
"""


def main() -> int:
    # Write nginx patch helper next to this script for upload.
    patch_helper = ROOT / "deploy" / "_patch_nginx_call_ws.py"
    patch_helper.write_text(
        "from pathlib import Path\n"
        "block = " + repr(NGINX_BLOCK) + "\n"
        "paths = list(Path('/etc/nginx/sites-enabled').glob('*'))\n"
        "for p in paths:\n"
        "    text = p.read_text()\n"
        "    if 'communications-ws' in text:\n"
        "        print('already', p)\n"
        "        break\n"
        "    if 'location /morpheus-ws/' in text:\n"
        "        text = text.replace('location /morpheus-ws/', block + '\\n    location /morpheus-ws/', 1)\n"
        "        p.write_text(text)\n"
        "        print('patched', p)\n"
        "        break\n"
        "else:\n"
        "    print('no_nginx_site_patched')\n",
        encoding="utf-8",
    )

    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    upload_files(ssh, pairs, REMOTE_APP)
    print(
        sudo_run(
            ssh,
            f"""
set -e
mkdir -p {REMOTE_APP}/services/call-events-ws
cd {REMOTE_APP}/services/call-events-ws
npm install --omit=dev > /tmp/ws-npm.log 2>&1
tail -n 8 /tmp/ws-npm.log
cp {REMOTE_APP}/services/call-events-ws/apex-call-events-ws.service /etc/systemd/system/apex-call-events-ws.service
systemctl daemon-reload
systemctl enable apex-call-events-ws
systemctl restart apex-call-events-ws
sleep 1
systemctl is-active apex-call-events-ws
curl -fsS http://127.0.0.1:8787/health || true
python3 {REMOTE_APP}/deploy/_patch_nginx_call_ws.py
nginx -t && systemctl reload nginx
echo NGINX_RELOADED
cd {REMOTE_APP}
grep -q '^CALL_EVENTS_WS_PUSH_URL=' .env || echo 'CALL_EVENTS_WS_PUSH_URL=http://127.0.0.1:8787/push' >> .env
php artisan config:clear >/dev/null 2>&1 || true
php artisan view:clear >/dev/null 2>&1 || true
npm run build > /tmp/vite-call-ws.log 2>&1
tail -n 16 /tmp/vite-call-ws.log
echo BUILD:$?
chown -R www-data:www-data {REMOTE_APP}/public/build {REMOTE_APP}/services/call-events-ws
grep -n "callEventsWsUrl\\|publishRealtimeWebSocket\\|new WebSocket" {REMOTE_APP}/resources/js/communications-webphone.js {REMOTE_APP}/app/Services/Communications/MorpheusCallEventService.php | head -30
""",
            check=False,
        )
    )
    ssh.close()
    print("WebSocket call-events bridge deployed. Hard refresh dialer (Ctrl+F5).")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
