#!/usr/bin/env python3
import sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import connect

ssh = connect()
cmds = [
    "curl -sS -o /dev/null -w 'https_7443 code=%{http_code} connect=%{time_connect}\\n' --max-time 10 -k https://apexone.morpheus.cx:7443/ || echo curl_failed",
    "nc -z -w 5 apexone.morpheus.cx 7443 && echo port_7443_open || echo port_7443_closed",
    "grep MORPHEUS_SIP /var/www/apexone/.env | sed 's/=.*/=***MASKED***/'",
]
for c in cmds:
    print("===", c)
    _, o, e = ssh.exec_command(f"echo btdev | sudo -S bash -lc {repr(c)}")
    print(o.read().decode())
    err = e.read().decode()
    if err.strip():
        print(err)
ssh.close()
