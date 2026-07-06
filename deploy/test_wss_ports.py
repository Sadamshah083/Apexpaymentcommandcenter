#!/usr/bin/env python3
import sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import connect

ssh = connect()
hosts = [
    "curl -sS -o /dev/null -w '443: code=%{http_code} t=%{time_connect}\\n' --max-time 8 https://apexone.morpheus.cx/ || echo fail",
    "curl -sS -o /dev/null -w '443/ws: code=%{http_code} t=%{time_connect}\\n' --max-time 8 -k https://apexone.morpheus.cx/ws || echo fail",
    "nc -z -w 5 apexone.morpheus.cx 443 && echo 443_open || echo 443_closed",
    "nc -z -w 5 apexone.morpheus.cx 5061 && echo 5061_open || echo 5061_closed",
    "nc -z -w 5 apexone.morpheus.cx 8089 && echo 8089_open || echo 8089_closed",
]
for c in hosts:
    print("===", c)
    _, o, e = ssh.exec_command(c)
    print(o.read().decode() + e.read().decode())
ssh.close()
