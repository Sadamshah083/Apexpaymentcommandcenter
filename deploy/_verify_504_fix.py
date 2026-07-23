#!/usr/bin/env python3
from __future__ import annotations

import sys
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]
NEW = {"host": "203.215.161.236", "user": "ateg", "password": "balitech1"}
REMOTE = "/var/www/apexone"


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(NEW["host"], username=NEW["user"], password=NEW["password"], timeout=40)
    sys.path.insert(0, str(ROOT))
    import deploy._ssh as ssh_mod
    from deploy._ssh import sudo_run, upload_files

    ssh_mod.HOST = NEW["host"]
    ssh_mod.USER = NEW["user"]
    ssh_mod.PASSWORD = NEW["password"]
    ssh_mod.REMOTE_APP = REMOTE

    upload_files(
        ssh,
        [(ROOT / "resources/views/maps-scraper/show.blade.php", "resources/views/maps-scraper/show.blade.php")],
        app_root=REMOTE,
    )
    print(
        sudo_run(
            ssh,
            r"""
chown www-data:www-data /var/www/apexone/resources/views/maps-scraper/show.blade.php
echo '=== FPM NOW ==='
ps -eo pid,etime,pcpu,cmd | awk '/php-fpm: pool www/ {print}' | head -15
echo '=== HEALTH ==='
curl -s -o /dev/null -w 'login_http=%{http_code} time=%{time_total}\n' https://crm.apexonepayments.com/portal/login
curl -s -o /dev/null -w 'admin_http=%{http_code} time=%{time_total}\n' https://crm.apexonepayments.com/admin/login
echo DONE_VERIFY
""",
        )
    )
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
