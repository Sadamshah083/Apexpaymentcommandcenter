#!/usr/bin/env python3
"""Fix unquoted COMMUNICATIONS_DEFAULT_CALLER_ID_NAME in production .env."""

from __future__ import annotations

import shlex
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import connect, sudo_run

SCRIPT = r"""
import pathlib, re
path = pathlib.Path("/var/www/apexone/.env")
text = path.read_text()
text = re.sub(
    r'^COMMUNICATIONS_DEFAULT_CALLER_ID_NAME=.*$',
    'COMMUNICATIONS_DEFAULT_CALLER_ID_NAME="ApexOne Payments"',
    text,
    flags=re.M,
)
if "COMMUNICATIONS_DEFAULT_CALLER_ID_NAME" not in text:
    text = text.rstrip() + '\nCOMMUNICATIONS_DEFAULT_CALLER_ID_NAME="ApexOne Payments"\n'
path.write_text(text)
print("env fixed")
"""


def main() -> int:
    ssh = connect()
    print(sudo_run(ssh, f"python3 -c {shlex.quote(SCRIPT)}"))
    print(
        sudo_run(
            ssh,
            "cd /var/www/apexone && sudo -u www-data php artisan config:clear && sudo -u www-data php artisan config:cache",
        )
    )
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
