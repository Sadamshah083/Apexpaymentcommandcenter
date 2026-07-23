#!/usr/bin/env python3
"""Verify import OOM fix is live and site responds."""
import paramiko

HOST = "203.215.161.236"
USER = "ateg"
PASS = "balitech1"
REMOTE = "/var/www/apexone"

cmds = [
    f"grep -n 'setReadFilter\\|SpreadsheetChunkReadFilter' {REMOTE}/app/Services/Workflow/WorkflowAiMapper.php | head",
    f"grep -n 'Import mode\\|Duplicate check\\|processing_mode' {REMOTE}/resources/views/workflows/create.blade.php {REMOTE}/resources/views/workflows/show.blade.php | head -20",
    f"cd {REMOTE} && php -r \"echo 'skip_phone_dedup='.(config('workflow.skip_phone_dedup') ? 'true' : 'false').PHP_EOL;\" 2>/dev/null || php artisan tinker --execute=\"echo config('workflow.skip_phone_dedup') ? 'true' : 'false';\"",
    "php -r \"echo ini_get('memory_limit').PHP_EOL;\"",
    "curl -s -o /dev/null -w 'login=%{http_code}\\n' https://crm.apexonepayments.com/login",
    "curl -s -o /dev/null -w 'create=%{http_code}\\n' https://crm.apexonepayments.com/admin/workflows/create",
    f"tail -n 5 /var/log/nginx/error.log | grep -i 'memory\\|workflows' || echo 'no recent memory errors in last lines'",
]

c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect(HOST, username=USER, password=PASS, timeout=20)
for cmd in cmds:
    print(">>>", cmd[:90])
    stdin, stdout, stderr = c.exec_command(cmd, timeout=60)
    out = stdout.read().decode()
    err = stderr.read().decode()
    print(out or err[:800])
    print("---")
c.close()

# upload show memory bump
sftp = paramiko.SSHClient()
sftp.set_missing_host_key_policy(paramiko.AutoAddPolicy())
sftp.connect(HOST, username=USER, password=PASS, timeout=20)
# use existing upload helper via shell
print("VERIFY_DONE")
sftp.close()
