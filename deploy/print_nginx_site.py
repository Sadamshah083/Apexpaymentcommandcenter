#!/usr/bin/env python3
from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import connect, sudo_run


def main() -> int:
    ssh = connect()
    print(sudo_run(ssh, "sed -n '1,260p' /etc/nginx/sites-available/apexone", check=False))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

