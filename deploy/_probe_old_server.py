#!/usr/bin/env python3
"""Probe old server (IP-only) without touching new server / domain."""

from __future__ import annotations

import os
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

import deploy._ssh as m

m.HOST = "203.215.160.44"
m.USER = "issac"
m.PASSWORD = "SadamShah123"
m.REMOTE_APP = "/var/www/apexone"

from deploy._ssh import REMOTE_APP, connect, sudo_run_batch


def main() -> int:
    ssh = connect()
    print(sudo_run_batch(ssh, [
        f"test -d {REMOTE_APP} && echo APP_OK || echo APP_MISSING",
        f"cd {REMOTE_APP} && grep -E '^(APP_URL|APP_ENV)=' .env | head -5",
        "hostname; hostname -I | awk '{print $1}'",
        "ls /etc/nginx/sites-enabled 2>/dev/null || ls /etc/apache2/sites-enabled 2>/dev/null || echo NO_WEB_SITES",
        "grep -RHn 'server_name\\|apexonepayments\\|203.215' /etc/nginx/sites-enabled /etc/nginx/sites-available 2>/dev/null | head -50 || true",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan --version 2>/dev/null | head -1 || true",
        "systemctl is-active nginx 2>/dev/null || systemctl is-active apache2 2>/dev/null || true",
    ], check=False))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
