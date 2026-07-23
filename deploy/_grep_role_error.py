#!/usr/bin/env python3
import os, sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
os.environ.setdefault("DEPLOY_PASSWORD", "balitech1")
import deploy._ssh as s
s.HOST = "203.215.161.236"
s.USER = "ateg"
s.PASSWORD = "balitech1"
s.REMOTE_APP = "/var/www/apexone"
from deploy._ssh import connect, sudo_run
ssh = connect()
out = sudo_run(ssh, "cd /var/www/apexone && grep -n 'Undefined constant\\|updateMemberRole\\|SherazBali\\|members/14/role' storage/logs/laravel.log | tail -n 40 | tr -cd '\\11\\12\\15\\40-\\176'")
print(out.encode("ascii", "replace").decode("ascii"))
ssh.close()
