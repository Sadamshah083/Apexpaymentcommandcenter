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
    print(sudo_run(ssh, "cd /var/www/apexone && php -r \"require 'vendor/autoload.php'; \\$a=require 'bootstrap/app.php'; \\$a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); echo Schema::hasColumn('workspace_spreadsheets','styles') ? 'styles_ok' : 'styles_missing';\""))
    print(sudo_run(ssh, "grep -n 'data-all-call-logs-close\\|data-recording-download\\|excel-fmt-btn\\|Auto-save' /var/www/apexone/resources/views/communications/agent-status/partials/panel.blade.php /var/www/apexone/resources/views/excel-sheets/partials/editor.blade.php | sed -n '1,20p'"))
    print(sudo_run(ssh, "grep -n 'scheduleAutosave\\|mutateSelectionStyles\\|agent-status-player__close' /var/www/apexone/resources/js/excel-sheet-editor.js /var/www/apexone/resources/css/app.css | sed -n '1,20p'"))
finally:
    ssh.close()
