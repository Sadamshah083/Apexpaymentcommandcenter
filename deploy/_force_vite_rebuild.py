#!/usr/bin/env python3
from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run  # noqa: E402


def main() -> int:
    ssh = connect()
    # Force rebuild; write status to a file to avoid unicode console issues
    cmd = (
        f"cd {REMOTE_APP} && npm run build > /tmp/apex_vite_build.log 2>&1; "
        f"echo EXIT:$? >> /tmp/apex_vite_build.log; "
        f"chown -R www-data:www-data {REMOTE_APP}/public/build; "
        f"tail -n 30 /tmp/apex_vite_build.log"
    )
    print(sudo_run(ssh, cmd))
    print("--- markers in built assets ---")
    print(sudo_run(ssh, f"grep -l dispatchCallEndedOnce {REMOTE_APP}/public/build/assets/*.js | head -5 || echo NONE"))
    print(sudo_run(ssh, f"grep -l tickRingTimer {REMOTE_APP}/public/build/assets/*.js | head -5 || echo NONE"))
    print(sudo_run(ssh, f"grep -l webrtc-rtp-confirmed {REMOTE_APP}/public/build/assets/*.js | head -5 || echo NONE"))
    print(sudo_run(ssh, f"ls -lt {REMOTE_APP}/public/build/assets/*webphone* {REMOTE_APP}/public/build/assets/*comm* 2>/dev/null | head -20"))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
