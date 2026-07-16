#!/usr/bin/env python3
"""Deploy fast hangup + call-events WS + disable incoming INVITE."""

from __future__ import annotations

import os
import shlex
import sys
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

NEW = {
    "host": "203.215.161.236",
    "user": "ateg",
    "password": os.environ.get("NEW_DEPLOY_PASSWORD", "balitech1"),
}
OLD = {
    "host": "203.215.160.44",
    "user": "issac",
    "password": os.environ.get("OLD_DEPLOY_PASSWORD", "SadamShah123"),
}
REMOTE_APP = "/var/www/apexone"
NEW_IP = "203.215.161.236"
DOMAIN = "crm.apexonepayments.com"

FILES = [
    "app/Http/Controllers/MorpheusHubController.php",
    "app/Services/Integrations/ZoomApiService.php",
    "resources/js/communications-webphone.js",
    "services/call-events-ws/server.mjs",
]


def connect(cfg: dict) -> paramiko.SSHClient:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(cfg["host"], username=cfg["user"], password=cfg["password"], timeout=30)
    return ssh


def sudo(ssh: paramiko.SSHClient, password: str, cmd: str, timeout: int = 300) -> str:
    full = f"echo {shlex.quote(password)} | sudo -S -p '' bash -lc {shlex.quote(cmd)}"
    _, stdout, stderr = ssh.exec_command(full, timeout=timeout)
    out = (stdout.read() + stderr.read()).decode(errors="replace")
    return out


def upload(ssh: paramiko.SSHClient, password: str) -> None:
    os.environ["DEPLOY_HOST"] = NEW["host"]
    os.environ["DEPLOY_USER"] = NEW["user"]
    os.environ["DEPLOY_PASSWORD"] = password
    import deploy._ssh as ssh_mod

    ssh_mod.HOST = NEW["host"]
    ssh_mod.USER = NEW["user"]
    ssh_mod.PASSWORD = password
    ssh_mod.REMOTE_APP = REMOTE_APP
    from deploy._ssh import upload_files

    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    print(f"Uploading {len(pairs)} files to {NEW['host']}...")
    upload_files(ssh, pairs, app_root=REMOTE_APP)


def main() -> int:
    new = connect(NEW)
    upload(new, NEW["password"])
    print("Building assets + restarting call-events-ws...")
    print(
        sudo(
            new,
            NEW["password"],
            f"""
set -e
cd {REMOTE_APP}
npm run build > /tmp/vite-hangup-ws.log 2>&1
echo BUILD:$?
tail -n 20 /tmp/vite-hangup-ws.log
chown -R www-data:www-data {REMOTE_APP}/public/build
sudo -u www-data php artisan view:clear
sudo -u www-data php artisan config:clear
systemctl restart apex-call-events-ws.service
systemctl is-active apex-call-events-ws.service
# quick hangup controller sanity
grep -n 'async' {REMOTE_APP}/app/Http/Controllers/MorpheusHubController.php | head -n 5
grep -n 'incoming calls disabled' {REMOTE_APP}/resources/js/communications-webphone.js | head -n 3
curl -fsS http://127.0.0.1:8787/health || true
echo
""",
            timeout=600,
        )
    )
    new.close()

    print("Patching OLD proxy for dedicated /communications-ws/ upgrade path...")
    old = connect(OLD)
    print(
        sudo(
            old,
            OLD["password"],
            f"""
set -e
python3 - <<'PY'
from pathlib import Path
path = Path('/etc/nginx/sites-available/apexone-proxy')
text = path.read_text()
marker = 'location /communications-ws/'
block = '''
    location /communications-ws/ {{
        proxy_pass http://apexone_new;
        proxy_http_version 1.1;
        proxy_buffering off;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection $connection_upgrade;
        proxy_set_header Host {DOMAIN};
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-Forwarded-Host {DOMAIN};
        proxy_read_timeout 86400s;
        proxy_send_timeout 86400s;
    }}
'''
if marker in text:
    print('OLD_PROXY_WS_ALREADY')
else:
    needle = '    location / {{'
    if needle not in text:
        raise SystemExit('missing location / in apexone-proxy')
    text = text.replace(needle, block + '\\n' + needle, 1)
    path.write_text(text)
    print('OLD_PROXY_WS_ADDED')
PY
nginx -t
systemctl reload nginx
curl -sk --http1.1 -o /dev/null -w 'ws_upgrade_probe=%{{http_code}}\\n' \
  -H 'Connection: Upgrade' -H 'Upgrade: websocket' \
  -H 'Sec-WebSocket-Version: 13' -H 'Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==' \
  https://127.0.0.1/communications-ws/ws?uuid=probe-hangup-fix || true
echo PROXY_WS_OK
""",
        )
    )
    old.close()
    print("Deploy complete.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
