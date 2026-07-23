#!/usr/bin/env python3
import os, sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
os.environ.setdefault("DEPLOY_PASSWORD", "balitech1")
import deploy._ssh as m
m.HOST = "203.215.161.236"
m.USER = "ateg"
m.PASSWORD = "balitech1"
from deploy._ssh import connect, sudo_run
ssh = connect()
try:
    print(sudo_run(ssh, "grep -n resolveBudget /var/www/apexone/app/Services/Communications/AgentStatusReportService.php; echo exit:$?"))
    print(sudo_run(ssh, "grep -n 'never block page load' /var/www/apexone/app/Services/Communications/AgentStatusReportService.php"))
finally:
    ssh.close()
