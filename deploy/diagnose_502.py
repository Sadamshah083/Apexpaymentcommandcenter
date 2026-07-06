#!/usr/bin/env python3
"""Check 502 and webphone HTTP on production."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import connect, sudo_run

def main() -> int:
    ssh = connect()

    cmds = [
        ("old error string", "grep -n \"not found in Morpheus\" /var/www/apexone/app/Services/Communications/CommunicationsWebphoneService.php || echo 'old string gone'"),
        ("nginx error", "tail -n 25 /var/log/nginx/error.log 2>/dev/null || echo 'no nginx err'"),
        ("php-fpm", "journalctl -u php8.3-fpm -n 20 --no-pager 2>/dev/null | tail -20"),
    ]

    for label, cmd in cmds:
        print(f"=== {label} ===")
        try:
            print(sudo_run(ssh, cmd, check=False))
        except Exception as e:
            print(e)
        print()

    _, o, _ = ssh.exec_command("curl -sS -o /dev/null -w '%{http_code} time=%{time_total}' https://crm.apexonepayments.com/up")
    print("HTTPS /up:", o.read().decode())

    _, o, _ = ssh.exec_command("curl -sS -o /dev/null -w '%{http_code} time=%{time_total}' https://crm.apexonepayments.com/admin/login")
    print("HTTPS /admin/login:", o.read().decode())

    ssh.close()
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
