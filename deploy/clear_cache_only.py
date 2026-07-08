#!/usr/bin/env python3
from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import connect, sudo_run

def main() -> int:
    ssh = connect()
    sudo_run(
        ssh,
        "cd /var/www/apexone && "
        "sudo -u www-data php artisan config:clear && "
        "sudo -u www-data php artisan view:clear && "
        "sudo -u www-data php artisan route:clear && "
        "sudo -u www-data php artisan config:cache",
        check=False,
    )
    _, stdout, _ = ssh.exec_command(
        "curl -sS -o /dev/null -w '%{http_code} %{redirect_url}' -L "
        "'https://crm.apexonepayments.com/admin/communications'"
    )
    print("communications:", stdout.read().decode().strip())
    ssh.close()
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
