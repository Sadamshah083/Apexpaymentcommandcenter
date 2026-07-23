#!/usr/bin/env python3
"""Fix old testing server: migrate missing columns + usermanagement path."""

from __future__ import annotations

import io
import os
import shlex
import sys
import tarfile
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]

OLD = {
    "host": "203.215.160.44",
    "user": "issac",
    "password": os.environ.get("OLD_DEPLOY_PASSWORD", "SadamShah123"),
}
APP = "/var/www/apexone"

FILES = [
    "routes/web.php",
    "app/Http/Controllers/WorkflowController.php",
    "resources/views/workflows/workspaces.blade.php",
    "database/migrations/2026_07_21_030000_add_lead_disposition_tags_segment.php",
    "database/migrations/2026_07_21_060000_create_lead_dispositions_table.php",
    "database/migrations/2026_07_21_220000_add_agent_restricted_to_workflows_table.php",
]


def log(msg: str) -> None:
    print(msg, flush=True)


def main() -> None:
    buf = io.BytesIO()
    with tarfile.open(fileobj=buf, mode="w:gz") as tar:
        for rel in FILES:
            path = ROOT / rel
            if not path.exists():
                raise SystemExit(f"Missing {rel}")
            tar.add(path, arcname=rel.replace("\\", "/"))

    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(OLD["host"], username=OLD["user"], password=OLD["password"], timeout=45)
    try:
        with ssh.open_sftp() as sftp:
            sftp.putfo(io.BytesIO(buf.getvalue()), "/tmp/apexone-um-fix.tgz")

        cmd = f"""
set -e
tar -xzf /tmp/apexone-um-fix.tgz -C {APP}
rm -f /tmp/apexone-um-fix.tgz
chown -R www-data:www-data {APP}/routes {APP}/app/Http/Controllers/WorkflowController.php {APP}/resources/views/workflows/workspaces.blade.php {APP}/database/migrations

cd {APP}
sudo -u www-data php artisan migrate --force --no-interaction
sudo -u www-data php artisan route:clear
sudo -u www-data php artisan view:clear
sudo -u www-data php artisan config:clear
sudo -u www-data php artisan cache:clear

# Ensure critical columns exist even if migration table was messy
mysql apexone -e "SHOW COLUMNS FROM workflow_leads LIKE 'last_disposition';" || true
mysql apexone -N -e "
SET @db := DATABASE();
SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='workflow_leads' AND COLUMN_NAME='last_disposition'
);
SET @sql := IF(@exists=0,
  'ALTER TABLE workflow_leads ADD COLUMN last_disposition VARCHAR(120) NULL AFTER last_contacted_at',
  'SELECT ''last_disposition already exists'''
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
"

mysql apexone -N -e "
SET @db := DATABASE();
SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='workflow_leads' AND COLUMN_NAME='contact_attempts'
);
SET @sql := IF(@exists=0,
  'ALTER TABLE workflow_leads ADD COLUMN contact_attempts INT UNSIGNED NULL DEFAULT 0',
  'SELECT ''contact_attempts already exists'''
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
"

mysql apexone -N -e "
SET @db := DATABASE();
SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='workflows' AND COLUMN_NAME='agent_restricted'
);
SET @sql := IF(@exists=0,
  'ALTER TABLE workflows ADD COLUMN agent_restricted TINYINT(1) NOT NULL DEFAULT 0',
  'SELECT ''agent_restricted already exists'''
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
"

php artisan route:list --path=usermanagement 2>/dev/null | head -20 || true
curl -s -o /dev/null -w 'um=%{{http_code}}\\n' -L http://127.0.0.1/admin/usermanagement
curl -s -o /dev/null -w 'legacy=%{{http_code}}\\n' -L http://127.0.0.1/admin/workspaces
curl -s -o /dev/null -w 'leads_api=%{{http_code}}\\n' -b /tmp/nocookie http://127.0.0.1/admin/communications/dialer/imported-leads?offset=0&per_page=1&pool=callable || true

# Verify column
mysql apexone -N -e "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='apexone' AND TABLE_NAME='workflow_leads' AND COLUMN_NAME IN ('last_disposition','contact_attempts','last_contacted_at');"

systemctl reload php8.3-fpm 2>/dev/null || systemctl reload php-fpm 2>/dev/null || true
echo FIX_OK
"""
        full = f"echo {shlex.quote(OLD['password'])} | sudo -S -p '' bash -lc {shlex.quote(cmd)}"
        _, stdout, stderr = ssh.exec_command(full, timeout=300)
        out = (stdout.read() + stderr.read()).decode(errors="replace")
        log(out)
        if "FIX_OK" not in out:
            raise SystemExit("Fix failed")
    finally:
        ssh.close()

    log("Done. Use http://203.215.160.44/admin/usermanagement")


if __name__ == "__main__":
    main()
