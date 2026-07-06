#!/usr/bin/env python3
"""Run ZoomApiService tests on production server."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run


def main() -> int:
    ssh = connect()
    print(sudo_run(
        ssh,
        f"cd {REMOTE_APP} && sudo -u www-data php artisan test --filter=ZoomApiServiceTest 2>&1",
        check=False,
    ))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
