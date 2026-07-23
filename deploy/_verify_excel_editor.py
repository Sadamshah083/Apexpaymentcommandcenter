#!/usr/bin/env python3
import os
import sys
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
    print(sudo_run(ssh, "cd /var/www/apexone && php -r \"require 'vendor/autoload.php'; \\$app=require 'bootstrap/app.php'; \\$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); echo Schema::hasTable('workspace_spreadsheets') ? 'sheet_table_ok' : 'missing';\""))
    print(sudo_run(ssh, "grep -n 'All call logs' /var/www/apexone/resources/views/layouts/partials/sidebar-nav-admin.blade.php /var/www/apexone/resources/views/layouts/partials/sidebar-nav-portal.blade.php"))
    print(sudo_run(ssh, "test -f /var/www/apexone/resources/js/excel-sheet-editor.js && echo editor_js_ok"))
finally:
    ssh.close()
