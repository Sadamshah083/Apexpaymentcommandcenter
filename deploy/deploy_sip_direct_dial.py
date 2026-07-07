#!/usr/bin/env python3
"""Deploy SIP-direct webphone dial mode (no click-to-call dependency)."""
from __future__ import annotations
import sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import REMOTE_APP, connect, set_env_vars, sudo_run_batch, upload_files

FILES = [
    "config/integrations.php",
    "app/Services/Communications/CommunicationsWebphoneService.php",
    "resources/js/communications-dialer.js",
    "resources/js/communications-webphone.js",
    "resources/views/communications/partials/dialer-form.blade.php",
]

ENV = {
    "MORPHEUS_WEBPHONE_DIAL_MODE": "sip",
    "MORPHEUS_WEBPHONE_AUTO_ANSWER": "false",
}

def main() -> int:
    pairs = [(ROOT / f, f.replace("\\", "/")) for f in FILES if (ROOT / f).is_file()]
    ssh = connect()
    set_env_vars(ssh, ENV)
    upload_files(ssh, pairs, app_root=REMOTE_APP)
    sudo_run_batch(ssh, [
        f"cd {REMOTE_APP} && npm run build",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan config:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan config:cache",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear",
        "systemctl reload php8.3-fpm 2>/dev/null || true",
    ])
    ssh.close()
    print("Done.")
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
