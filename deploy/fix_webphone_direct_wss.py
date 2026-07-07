#!/usr/bin/env python3
"""Point webphone at direct Morpheus WSS + fix SIP display name."""
from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, set_env_vars, sudo_run, sudo_run_batch, upload_files

DIRECT_WSS = "wss://apexone.morpheus.cx:7443/"

FILES = [
    "app/Support/MorpheusSipIdentity.php",
    "app/Services/Communications/CommunicationsWebphoneService.php",
    "app/Services/Communications/CommunicationsAgentService.php",
    "resources/js/communications-webphone.js",
    "deploy/nginx-apexone.conf",
]


def patch_nginx(ssh) -> None:
    script = r'''
import pathlib, re
path = pathlib.Path("/etc/nginx/sites-available/apexone")
text = path.read_text()
map_block = """map $http_upgrade $connection_upgrade {
    default upgrade;
    ''      close;
}
"""
if "connection_upgrade" not in text:
    text = map_block + "\n" + text
old = re.search(r"location /morpheus-ws/ \{.*?\n    \}", text, re.S)
new = """    location /morpheus-ws/ {
        proxy_pass https://apexone.morpheus.cx:7443/;
        proxy_http_version 1.1;
        proxy_buffering off;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection $connection_upgrade;
        proxy_set_header Host apexone.morpheus.cx;
        proxy_set_header Origin https://apexone.morpheus.cx;
        proxy_set_header Sec-WebSocket-Key $http_sec_websocket_key;
        proxy_set_header Sec-WebSocket-Version $http_sec_websocket_version;
        proxy_set_header Sec-WebSocket-Extensions $http_sec_websocket_extensions;
        proxy_set_header Sec-WebSocket-Protocol $http_sec_websocket_protocol;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_ssl_server_name on;
        proxy_read_timeout 86400s;
        proxy_send_timeout 86400s;
    }"""
if old:
    text = text[:old.start()] + new + text[old.end():]
    path.write_text(text)
    print("nginx patched")
else:
    print("nginx location missing")
'''
    sudo_run(ssh, f"python3 -c {__import__('shlex').quote(script)}", check=False)


def main() -> int:
    pairs = [(ROOT / rel, rel.replace("\\", "/")) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    print("Setting MORPHEUS_SIP_WSS_URL to direct Morpheus WSS...")
    set_env_vars(ssh, {"MORPHEUS_SIP_WSS_URL": DIRECT_WSS})
    print(f"Uploading {len(pairs)} files...")
    upload_files(ssh, pairs, app_root=REMOTE_APP)
    patch_nginx(ssh)
    sudo_run_batch(ssh, [
        "nginx -t",
        "systemctl reload nginx",
        f"cd {REMOTE_APP} && npm run build",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan config:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan config:cache",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear",
        "systemctl reload php8.3-fpm 2>/dev/null || true",
    ])
    print(sudo_run(ssh, f"grep MORPHEUS_SIP_WSS_URL {REMOTE_APP}/.env", check=False))
    ssh.close()
    print("Done.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
