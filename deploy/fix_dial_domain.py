#!/usr/bin/env python3
"""Fix PSTN INVITE dial domain (morpheus.cx not pbx.local)."""
from __future__ import annotations
import sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import REMOTE_APP, connect, sudo_run_batch, upload_files

FILES = [
    "app/Services/Communications/CommunicationsWebphoneService.php",
    "resources/js/communications-webphone.js",
    "resources/js/communications-dialer.js",
]

def main() -> int:
    pairs = [(ROOT / f, f.replace("\\", "/")) for f in FILES]
    ssh = connect()
    upload_files(ssh, pairs, app_root=REMOTE_APP)
    sudo_run_batch(ssh, [
        f"cd {REMOTE_APP} && npm run build",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan config:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan config:cache",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear",
    ])
    ssh.close()
    print("Done — dial_domain=apexone.morpheus.cx for PSTN INVITE.")
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
