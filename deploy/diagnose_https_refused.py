#!/usr/bin/env python3
"""Diagnose why public HTTPS refuses connections."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import connect, sudo_run


def main() -> int:
    ssh = connect()

    cmds = [
        ("nginx active", "systemctl is-active nginx || true"),
        ("nginx enabled", "systemctl is-enabled nginx || true"),
        ("nginx test", "nginx -t 2>&1 | tail -n 20"),
        ("listeners", "ss -lntp | egrep ':(80|443)\\b' || true"),
        ("ufw", "ufw status verbose 2>/dev/null || echo 'ufw not installed'"),
        ("iptables", "iptables -S | head -60 2>/dev/null || echo 'iptables not available'"),
        ("ip addr", "ip -br addr || true"),
        ("routes", "ip route | head -50 || true"),
        ("curl local http", "curl -sS -o /dev/null -w 'local80 %{http_code} %{time_total}s\\n' http://127.0.0.1/up || true"),
        ("curl local https", "curl -k -sS -o /dev/null -w 'local443 %{http_code} %{time_total}s\\n' https://127.0.0.1/up || true"),
        ("curl public http", "curl -sS -o /dev/null -w 'pub80 %{http_code} %{time_total}s\\n' http://203.215.160.44/up || true"),
        ("curl public https", "curl -k -sS -o /dev/null -w 'pub443 %{http_code} %{time_total}s\\n' https://203.215.160.44/up || true"),
        ("nginx access last", "tail -n 5 /var/log/nginx/access.log 2>/dev/null || true"),
        ("nginx error last", "tail -n 20 /var/log/nginx/error.log 2>/dev/null || true"),
    ]

    for label, cmd in cmds:
        print(f"=== {label} ===")
        print(sudo_run(ssh, cmd, check=False).strip())
        print()

    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

