#!/usr/bin/env python3
import sys
import time
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import connect, sudo_run

ssh = connect()
print(sudo_run(ssh, "systemctl is-active php8.3-fpm nginx", check=False))
time.sleep(2)
for path in ["/up", "/admin/login"]:
    _, o, _ = ssh.exec_command(
        f"curl -sS -o /dev/null -w '%{{http_code}} time=%{{time_total}}' https://crm.apexonepayments.com{path}"
    )
    print(path, o.read().decode().strip())
ssh.close()
