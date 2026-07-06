#!/usr/bin/env python3
"""Smoke-test communications hub endpoints on production."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import connect, sudo_run


def main() -> int:
    ssh = connect()

    checks = [
        ("php -l ZoomApiService", "php -l /var/www/apexone/app/Services/Integrations/ZoomApiService.php"),
        ("routes", "cd /var/www/apexone && sudo -u www-data php artisan route:list --path=communications/morpheus 2>&1 | head -15"),
        ("up", "curl -sS -o /dev/null -w '%{http_code}' https://crm.apexonepayments.com/up"),
        ("login", "curl -sS -o /dev/null -w '%{http_code}' https://crm.apexonepayments.com/admin/login"),
        ("comm redirect", "curl -sS -o /dev/null -w '%{http_code}' https://crm.apexonepayments.com/admin/communications"),
        ("webphone js", "curl -sS -o /dev/null -w '%{http_code}' https://crm.apexonepayments.com/build/assets/communications-webphone-CLegaS7o.js"),
    ]

    for label, cmd in checks:
        print(f"=== {label} ===")
        if label.startswith("php") or label == "routes":
            print(sudo_run(ssh, cmd, check=False).strip())
        else:
            _, o, e = ssh.exec_command(cmd)
            print(o.read().decode().strip() + e.read().decode().strip())

    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
