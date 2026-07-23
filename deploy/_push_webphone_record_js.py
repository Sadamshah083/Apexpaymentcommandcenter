#!/usr/bin/env python3
import sys
from pathlib import Path
import paramiko

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
import deploy._ssh as m

m.HOST = "203.215.161.236"
m.USER = "ateg"
m.PASSWORD = "balitech1"
m.REMOTE_APP = "/var/www/apexone"
from deploy._ssh import sudo_run, upload_files

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect(m.HOST, username=m.USER, password=m.PASSWORD, timeout=40)
upload_files(
    ssh,
    [(ROOT / "resources/js/communications-webphone.js", "resources/js/communications-webphone.js")],
    app_root=m.REMOTE_APP,
)
print(
    sudo_run(
        ssh,
        "cd /var/www/apexone && chown www-data:www-data resources/js/communications-webphone.js && npm run build --silent && grep -n 'Recording started' public/build/assets/communications-*.js | head -3 && echo OK",
    )
)
ssh.close()
