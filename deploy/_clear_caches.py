#!/usr/bin/env python3
from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run  # noqa: E402


def main() -> int:
    ssh = connect()
    print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear && sudo -u www-data php artisan config:clear"))
    print(sudo_run(ssh, "systemctl reload php8.3-fpm || systemctl reload php-fpm || true", check=False))
    print("health", sudo_run(ssh, "curl -fsS -o /dev/null -w '%{http_code}' https://crm.apexonepayments.com/up"))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
