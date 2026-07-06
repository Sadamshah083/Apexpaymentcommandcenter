#!/usr/bin/env python3
import sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import connect, sudo_run

ssh = connect()
print("Services:", sudo_run(ssh, "systemctl is-active php8.3-fpm nginx", check=False))
cmds = [
    "curl -fsS https://crm.apexonepayments.com/up",
    "curl -fsSI https://crm.apexonepayments.com/build/manifest.json | head -3",
    "curl -fsSI https://crm.apexonepayments.com/build/assets/communications-webphone-BB-BMGNG.js | head -2",
    "grep -l 'floating-popup' /var/www/apexone/resources/views/communications/partials/webphone-floating-popup.blade.php && echo popup_blade_ok",
]
for c in cmds:
    print("===", c[:80])
    _, o, e = ssh.exec_command(c)
    print(o.read().decode() + e.read().decode())
ssh.close()
