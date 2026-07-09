#!/usr/bin/env python3
"""Set webphone SIP dial mode on production."""
from __future__ import annotations
import sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import REMOTE_APP, connect, set_env_vars, sudo_run_batch, upload_files

def main() -> int:
    ssh = connect()
    set_env_vars(ssh, {
        "MORPHEUS_WEBPHONE_DIAL_MODE": "sip",
        "MORPHEUS_SIP_WSS_URL": "wss://apexone.morpheus.cx:7443/",
    })
    pairs = [
        (ROOT / "resources/js/communications-dialer.js", "resources/js/communications-dialer.js"),
    ]
    upload_files(ssh, pairs, app_root=REMOTE_APP)
    sudo_run_batch(ssh, [
        f"cd {REMOTE_APP} && npm run build",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan config:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan config:cache",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear",
    ])
    ssh.close()
    print("Done — SIP dial mode only, no API fallback.")
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
