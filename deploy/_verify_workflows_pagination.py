#!/usr/bin/env python3
import os
import paramiko

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect(
    "203.215.161.236",
    username="ateg",
    password=os.environ.get("NEW_DEPLOY_PASSWORD", "balitech1"),
    timeout=30,
)
_, out, _ = ssh.exec_command(
    "python3 - <<'PY'\n"
    "from pathlib import Path\n"
    "css=Path('/var/www/apexone/resources/css/app.css').read_text(errors='replace')\n"
    "print('page_contrast', 'background: #e2e8f0 !important' in css and 'color: #0f172a !important' in css)\n"
    "ctl=Path('/var/www/apexone/app/Http/Controllers/WorkflowController.php').read_text(errors='replace')\n"
    "print('no_full_groupby', 'never GROUP BY' in ctl)\n"
    "print('files_page', 'files_page' in ctl)\n"
    "js=Path('/var/www/apexone/resources/js/pagination-preserve.js').read_text(errors='replace')\n"
    "print('hard_nav_workflows', 'heavyList' in js)\n"
    "svc=Path('/var/www/apexone/app/Services/Workflow/WorkflowDashboardService.php').read_text(errors='replace')\n"
    "print('leads_latest_id', \"latest('id')\" in svc)\n"
    "PY"
)
print(out.read().decode())
ssh.close()
