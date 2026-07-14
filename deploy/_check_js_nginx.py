#!/usr/bin/env python3
from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run


def main() -> int:
    ssh = connect()
    out = sudo_run(
        ssh,
        f"ls -lt {REMOTE_APP}/public/build/assets/call-monitoring*.js | head -3; "
        f"grep -o 'navOnly' {REMOTE_APP}/public/build/assets/call-monitoring*.js | wc -l; "
        f"grep -o '30000' {REMOTE_APP}/public/build/assets/call-monitoring*.js | wc -l; "
        f"sed -n '1,120p' /etc/nginx/sites-enabled/apexone",
        check=False,
    )
    print(out)
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
