#!/usr/bin/env python3
"""Fix import HTTP 500 (PhpSpreadsheet OOM) and restore AI/duplicate import options."""
from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
NEW = {"host": "203.215.161.236", "user": "ateg", "password": "balitech1"}
REMOTE = "/var/www/apexone"
FILES = [
    "app/Services/Workflow/WorkflowAiMapper.php",
    "app/Services/Workflow/WorkflowService.php",
    "app/Services/Pipeline/LeadImportDedupService.php",
    "app/Http/Controllers/WorkflowController.php",
    "app/Jobs/ProcessWorkflowJob.php",
    "app/Support/SpreadsheetChunkReadFilter.php",
    "config/workflow.php",
    "resources/views/workflows/create.blade.php",
    "resources/views/workflows/show.blade.php",
]


def main() -> int:
    import paramiko

    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(NEW["host"], username=NEW["user"], password=NEW["password"], timeout=40)

    sys.path.insert(0, str(ROOT))
    import deploy._ssh as ssh_mod

    ssh_mod.HOST = NEW["host"]
    ssh_mod.USER = NEW["user"]
    ssh_mod.PASSWORD = NEW["password"]
    ssh_mod.REMOTE_APP = REMOTE
    from deploy._ssh import upload_files

    pairs = [(ROOT / rel, rel) for rel in FILES]
    missing = [str(p) for p, _ in pairs if not p.is_file()]
    if missing:
        print("Missing:", *missing, sep="\n ")
        return 1

    print(f"Uploading {len(pairs)} files...")
    upload_files(ssh, pairs, app_root=REMOTE)

    files_chown = " ".join(f"{REMOTE}/{f}" for f in FILES)
    inner = f"""
set -e
cd {REMOTE}
chown www-data:www-data {files_chown}

# Raise PHP memory so large spreadsheet header reads never 500
for ini in /etc/php/*/fpm/php.ini /etc/php/*/cli/php.ini; do
  [ -f "$ini" ] || continue
  sed -i 's/^memory_limit\\s*=.*/memory_limit = 512M/' "$ini" || true
  sed -i 's/^upload_max_filesize\\s*=.*/upload_max_filesize = 64M/' "$ini" || true
  sed -i 's/^post_max_size\\s*=.*/post_max_size = 64M/' "$ini" || true
done
for conf in /etc/php/*/fpm/pool.d/*.conf; do
  [ -f "$conf" ] || continue
  if grep -q 'php_admin_value\\[memory_limit\\]' "$conf"; then
    sed -i 's#php_admin_value\\[memory_limit\\].*#php_admin_value[memory_limit] = 512M#' "$conf" || true
  else
    printf '\\nphp_admin_value[memory_limit] = 512M\\n' >> "$conf"
  fi
  if grep -q 'php_admin_value\\[upload_max_filesize\\]' "$conf"; then
    sed -i 's#php_admin_value\\[upload_max_filesize\\].*#php_admin_value[upload_max_filesize] = 64M#' "$conf" || true
    sed -i 's#php_admin_value\\[post_max_size\\].*#php_admin_value[post_max_size] = 64M#' "$conf" || true
  else
    printf 'php_admin_value[upload_max_filesize] = 64M\\nphp_admin_value[post_max_size] = 64M\\n' >> "$conf"
  fi
done

# Ensure duplicate skipping is enabled (override any stale env default)
if grep -q '^WORKFLOW_SKIP_PHONE_DEDUP=' .env; then
  sed -i 's/^WORKFLOW_SKIP_PHONE_DEDUP=.*/WORKFLOW_SKIP_PHONE_DEDUP=false/' .env
else
  printf '\\nWORKFLOW_SKIP_PHONE_DEDUP=false\\n' >> .env
fi
if grep -q '^WORKFLOW_SKIP_CROSS_IMPORT_PHONE_DEDUP=' .env; then
  sed -i 's/^WORKFLOW_SKIP_CROSS_IMPORT_PHONE_DEDUP=.*/WORKFLOW_SKIP_CROSS_IMPORT_PHONE_DEDUP=false/' .env
else
  printf 'WORKFLOW_SKIP_CROSS_IMPORT_PHONE_DEDUP=false\\n' >> .env
fi

php -l app/Services/Workflow/WorkflowAiMapper.php
php -l app/Services/Workflow/WorkflowService.php
php -l app/Services/Pipeline/LeadImportDedupService.php
php -l app/Http/Controllers/WorkflowController.php
php -l app/Jobs/ProcessWorkflowJob.php

php artisan view:clear
php artisan config:clear
php artisan config:cache

grep -n 'SpreadsheetChunkReadFilter\\|setReadFilter\\|Import mode\\|Duplicate check\\|skip_phone_dedup' \
  app/Services/Workflow/WorkflowAiMapper.php \
  resources/views/workflows/create.blade.php \
  resources/views/workflows/show.blade.php \
  config/workflow.php | head -40

php -r "echo 'memory_limit='.ini_get('memory_limit').PHP_EOL;"
systemctl reload php8.3-fpm 2>/dev/null || systemctl reload php*-fpm 2>/dev/null || true
echo DONE_IMPORT_OOM_FIX
"""
    from deploy._ssh import sudo_run

    print(sudo_run(ssh, inner))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
