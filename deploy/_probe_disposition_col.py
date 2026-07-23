"""Check disposition column + recording_status fix on prod."""
from __future__ import annotations

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


def main() -> int:
    ssh = connect()
    try:
        print(sudo_run(ssh, r"""
cd /var/www/apexone
php artisan tinker --execute="echo collect(\DB::select(\"SHOW COLUMNS FROM communication_call_logs LIKE 'disposition'\"))->toJson();"
echo '---'
grep -n "recording_status" app/Services/Communications/CommunicationsDataService.php | head -10
sed -n '115,145p' app/Services/Communications/CommunicationsDataService.php
echo '---'
php artisan migrate:status 2>/dev/null | grep -i disposition || true
ls database/migrations/*disposition* 2>/dev/null || true
""", check=False))
    finally:
        ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
