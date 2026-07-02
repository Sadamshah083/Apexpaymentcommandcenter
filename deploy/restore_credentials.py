#!/usr/bin/env python3
"""Restore ApexPayments demo credentials on production."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import connect, set_env_vars, sudo_run, upload_files


def main() -> int:
    ssh = connect()
    upload_files(ssh, [(ROOT / "scripts/reset-apex-credentials.php", "scripts/reset-apex-credentials.php")])
    set_env_vars(ssh, {"PRODUCTION_ADMIN_PASSWORD": "rwlt4NBN2MtIbQ0A"})
    out = sudo_run(ssh, "cd /var/www/apexone && sudo -u www-data php scripts/reset-apex-credentials.php")
    print(out)
    count = sudo_run(
        ssh,
        "cd /var/www/apexone && sudo -u www-data php -r "
        "'require \"vendor/autoload.php\"; $app=require \"bootstrap/app.php\"; "
        "$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); "
        "echo App\\Models\\User::count();'",
    )
    print(f"User count on server: {count}")
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
