#!/usr/bin/env python3
from __future__ import annotations
import sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import connect, sudo_run

ssh = connect()
cmds = {
    "resolveWssUrl": "grep -n 'resolveWssUrl\\|sipAuthUser\\|configuredOutboundDid' -A25 /var/www/apexone/app/Services/Communications/CommunicationsWebphoneService.php | head -80",
    "env wss": "grep -E 'MORPHEUS_SIP|MORPHEUS_WEBRTC' /var/www/apexone/.env",
    "proxy curl": "curl -sk --http1.1 -D - -o /dev/null --max-time 10 -H 'Connection: Upgrade' -H 'Upgrade: websocket' -H 'Sec-WebSocket-Version: 13' -H 'Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==' https://crm.apexonepayments.com/morpheus-ws/ws 2>&1 | head -20",
    "listen": "grep -n listen /etc/nginx/sites-enabled/apexone | head -5",
}
for k,v in cmds.items():
    print('===', k, '===')
    print(sudo_run(ssh, v, check=False))
ssh.close()
