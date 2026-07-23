#!/usr/bin/env python3
"""Inspect old + new server nginx / app listen config."""

from __future__ import annotations

import os
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

import deploy._ssh as m
from deploy._ssh import connect, sudo_run_batch


def probe(host: str, user: str, password: str, label: str) -> None:
    m.HOST = host
    m.USER = user
    m.PASSWORD = password
    m.REMOTE_APP = "/var/www/apexone"
    ssh = connect()
    print(f"\n===== {label} {host} =====")
    print(sudo_run_batch(ssh, [
        "ls -la /etc/nginx/sites-enabled",
        "ss -lntp | grep -E ':80|:443|:8080' || netstat -lntp 2>/dev/null | grep -E ':80|:443|:8080' || true",
        "test -f /etc/nginx/sites-available/apexone && head -n 40 /etc/nginx/sites-available/apexone || echo NO_APEXONE_SITE",
        "test -f /etc/nginx/sites-enabled/apexone-proxy && head -n 60 /etc/nginx/sites-enabled/apexone-proxy || echo NO_PROXY_ENABLED",
        "cd /var/www/apexone && grep -E '^(APP_URL|APP_ENV|SESSION_DOMAIN)=' .env || true",
        "systemctl is-active php8.3-fpm 2>/dev/null || systemctl is-active php8.2-fpm 2>/dev/null || systemctl is-active php-fpm 2>/dev/null || true",
    ], check=False))
    ssh.close()


def main() -> int:
    probe("203.215.160.44", "issac", "SadamShah123", "OLD")
    probe("203.215.161.236", "ateg", "balitech1", "NEW")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
