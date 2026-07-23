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
m.REMOTE_APP = "/var/www/apexone"
from deploy._ssh import connect, sudo_run

ssh = connect()
try:
    print("=== method ===")
    print(sudo_run(ssh, "grep -n looksLikePhoneNumber /var/www/apexone/app/Support/LeadContactDisplay.php | head -5", check=False))
    print("=== detect ===")
    print(sudo_run(ssh, "grep -n inferHeadersFromContent /var/www/apexone/app/Support/SpreadsheetHeaderDetector.php | head -3", check=False))
    print("=== workers ===")
    print(sudo_run(ssh, "systemctl list-units --type=service --all | grep -Ei 'queue|horizon|apex|worker' | head -30", check=False))
    print("=== supervisor ===")
    print(sudo_run(ssh, "ls /etc/supervisor/conf.d 2>/dev/null; supervisorctl status 2>/dev/null | head -30", check=False))
finally:
    ssh.close()
