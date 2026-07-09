#!/usr/bin/env python3
import sys
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from deploy._ssh import connect, sudo_run
ssh = connect()
print(sudo_run(ssh, "nginx -t 2>&1", check=False))
print(sudo_run(ssh, "head -30 /etc/nginx/sites-available/apexone", check=False))
ssh.close()
