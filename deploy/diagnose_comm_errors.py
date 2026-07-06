#!/usr/bin/env python3
"""Diagnose 502/500 on communications hub and hangup."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run


def main() -> int:
    ssh = connect()

    checks = [
        ("php syntax ZoomApiService", f"php -l {REMOTE_APP}/app/Services/Integrations/ZoomApiService.php"),
        ("php syntax MorpheusHubController", f"php -l {REMOTE_APP}/app/Http/Controllers/MorpheusHubController.php"),
        ("php-fpm status", "systemctl is-active php8.3-fpm"),
        ("laravel log tail", f"tail -n 40 {REMOTE_APP}/storage/logs/laravel.log"),
        ("nginx error tail", "tail -n 30 /var/log/nginx/error.log"),
        ("php-fpm journal", "journalctl -u php8.3-fpm -n 25 --no-pager"),
    ]

    for label, cmd in checks:
        print(f"=== {label} ===")
        try:
            print(sudo_run(ssh, cmd, check=False))
        except Exception as e:
            print(e)
        print()

    curls = [
        "curl -sS -o /dev/null -w 'up %{http_code} %{time_total}s\\n' https://crm.apexonepayments.com/up",
        "curl -sS -o /dev/null -w 'login %{http_code} %{time_total}s\\n' https://crm.apexonepayments.com/admin/login",
    ]
    for c in curls:
        _, o, e = ssh.exec_command(c)
        print(o.read().decode() + e.read().decode())

    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
