#!/usr/bin/env python3
from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run  # noqa: E402


def main() -> int:
    ssh = connect()
    sudo_run(
        ssh,
        f"cd {REMOTE_APP} && npm run build > /tmp/apex_vite_build.log 2>&1; echo EXIT:$? >> /tmp/apex_vite_build.log; chown -R www-data:www-data {REMOTE_APP}/public/build",
    )
    checks = [
        "webrtc-rtp-confirmed",
        "Ringing time",
        "Destination answer inferred",
        "Both sides connected",
    ]
    for needle in checks:
        found = sudo_run(
            ssh,
            f"grep -l {repr(needle)} {REMOTE_APP}/public/build/assets/*.js 2>/dev/null | head -3 || true",
            check=False,
        )
        print(f"{needle}: {found or 'MISSING'}")

    exit_line = sudo_run(ssh, "tail -n 5 /tmp/apex_vite_build.log | tr -cd '\\11\\12\\15\\40-\\176'")
    print(exit_line)
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
