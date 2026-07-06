#!/usr/bin/env python3
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import connect

ssh = connect()
cmds = [
    'curl -sS -o /dev/null -w "crm_wss code=%{http_code}\\n" --max-time 10 -k '
    '-H "Connection: Upgrade" -H "Upgrade: websocket" -H "Sec-WebSocket-Version: 13" '
    '-H "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==" '
    'https://crm.apexonepayments.com/morpheus-ws/ws',
    'curl -fsSI https://crm.apexonepayments.com/build/assets/communications-webphone-v_gibKoA.js | head -1',
    'curl -fsSI https://crm.apexonepayments.com/build/assets/communications-dialer-Ce23FZFs.js | head -1',
]
for c in cmds:
    print('===', c[:90])
    _, o, e = ssh.exec_command(c)
    print(o.read().decode() + e.read().decode())
ssh.close()
