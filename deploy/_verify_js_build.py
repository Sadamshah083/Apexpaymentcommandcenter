#!/usr/bin/env python3
from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run  # noqa: E402


def main() -> int:
    ssh = connect()
    print(sudo_run(ssh, f"ls -lt {REMOTE_APP}/public/build/assets/communications-webphone*.js | head -5"))
    print("---")
    print(sudo_run(ssh, f"grep -l dispatchCallEndedOnce {REMOTE_APP}/public/build/assets/communications-webphone*.js || echo NONE"))
    print(sudo_run(ssh, f"grep -l tickRingTimer {REMOTE_APP}/public/build/assets/communications-webphone*.js || echo NONE"))
    print(sudo_run(ssh, f"grep -l webrtc-rtp-confirmed {REMOTE_APP}/public/build/assets/communications-webphone*.js || echo NONE"))
    print(sudo_run(ssh, f"grep -n answeredActiveCallStates {REMOTE_APP}/app/Services/Integrations/ZoomApiService.php | head -5"))
    print(sudo_run(ssh, f"grep -n 'activeCallsCache = null' {REMOTE_APP}/app/Services/Integrations/ZoomApiService.php | head -5"))
    # Force rebuild if JS missing markers
    built = sudo_run(ssh, f"grep -l dispatchCallEndedOnce {REMOTE_APP}/public/build/assets/communications-webphone*.js 2>/dev/null || true", check=False)
    if "communications-webphone" not in built:
        print("JS markers missing — rebuilding assets...")
        print(sudo_run(ssh, f"cd {REMOTE_APP} && npm run build && chown -R www-data:www-data {REMOTE_APP}/public/build"))
        print(sudo_run(ssh, f"grep -l dispatchCallEndedOnce {REMOTE_APP}/public/build/assets/communications-webphone*.js || echo STILL_NONE"))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
