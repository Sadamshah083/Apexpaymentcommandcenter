#!/usr/bin/env python3
import os
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
os.environ.setdefault("DEPLOY_PASSWORD", "balitech1")

import deploy._ssh as ssh_mod

ssh_mod.HOST = "203.215.161.236"
ssh_mod.USER = "ateg"
ssh_mod.PASSWORD = "balitech1"
ssh_mod.REMOTE_APP = "/var/www/apexone"

from deploy._ssh import connect, sudo_run

ssh = connect()
try:
    print(sudo_run(ssh, "ls -lt /var/www/apexone/public/build/assets/app-*.css | sed -n '1,3p'"))
    print("---")
    print(sudo_run(ssh, "grep -n 'assign-leads-panel__fields\\|excel-sheet-preview' /var/www/apexone/resources/css/app.css | sed -n '1,12p'"))
    print("---")
    print(sudo_run(ssh, "grep -n 'Excel Sheets' /var/www/apexone/resources/views/layouts/partials/sidebar-nav-admin.blade.php /var/www/apexone/resources/views/layouts/partials/sidebar-nav-portal.blade.php"))
    print("---")
    print(sudo_run(ssh, "php -l /var/www/apexone/app/Http/Controllers/ExcelSheetController.php"))
    print(sudo_run(ssh, "php -l /var/www/apexone/app/Http/Controllers/WorkflowController.php"))
finally:
    ssh.close()
