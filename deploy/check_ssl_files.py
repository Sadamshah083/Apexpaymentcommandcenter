#!/usr/bin/env python3
from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import connect, sudo_run


def main() -> int:
    ssh = connect()
    print("=== letsencrypt live ===")
    print(sudo_run(ssh, "ls -la /etc/letsencrypt/live 2>/dev/null || true", check=False))
    print("=== crm cert paths ===")
    print(sudo_run(ssh, "ls -la /etc/letsencrypt/live/crm.apexonepayments.com 2>/dev/null || true", check=False))
    print("=== nginx snippets ===")
    print(sudo_run(ssh, "ls -la /etc/nginx/snippets 2>/dev/null || true", check=False))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

