#!/usr/bin/env python3
from __future__ import annotations
import sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import connect, sudo_run

ssh = connect()
for label, cmd in [
    ("CLICK2CALL", "sed -n '20,90p' /var/www/apexone/app/Services/Communications/ZoomClickToCallService.php"),
    ("WEBPHONE", "sed -n '750,930p' /var/www/apexone/resources/js/communications-webphone.js"),
    ("WSS", "curl -sk -D - -o /dev/null --max-time 10 -H 'Connection: Upgrade' -H 'Upgrade: websocket' -H 'Sec-WebSocket-Version: 13' -H 'Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==' https://crm.apexonepayments.com/morpheus-ws/ws 2>&1"),
    ("WSS DIRECT", "curl -sk -D - -o /dev/null --max-time 10 -H 'Connection: Upgrade' -H 'Upgrade: websocket' -H 'Sec-WebSocket-Version: 13' -H 'Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==' https://apexone.morpheus.cx:7443/ws 2>&1 | head -25"),
    ("NGINX ERR", "tail -30 /var/log/nginx/error.log"),
]:
    print(f"=== {label} ===")
    print(sudo_run(ssh, cmd, check=False))
ssh.close()
