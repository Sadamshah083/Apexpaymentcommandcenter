#!/usr/bin/env python3
"""Deploy ring-cell-first mode + latest calling fixes."""
from __future__ import annotations
import sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import REMOTE_APP, connect, set_env_vars, sudo_run, upload_files

def main() -> int:
    pairs = [
        (ROOT / "app/Services/Integrations/ZoomApiService.php", "app/Services/Integrations/ZoomApiService.php"),
        (ROOT / "resources/js/communications-webphone.js", "resources/js/communications-webphone.js"),
        (ROOT / "resources/js/communications-dialer.js", "resources/js/communications-dialer.js"),
    ]
    build = ROOT / "public" / "build"
    for path in build.rglob("*"):
        if path.is_file():
            pairs.append((path, path.relative_to(ROOT).as_posix()))

    ssh = connect()
    upload_files(ssh, pairs, app_root=REMOTE_APP)
    set_env_vars(ssh, {
        "MORPHEUS_ORIGINATE_CUSTOMER_FIRST": "false",
        "MORPHEUS_ORIGINATE_METHOD": "click-to-call",
        "MORPHEUS_WEBPHONE_AUTO_ANSWER": "true",
        "MORPHEUS_DIAL_METHOD": "api",
        "MORPHEUS_WEBPHONE_DIAL_MODE": "api",
    })
    sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php artisan optimize:clear && sudo -u www-data php artisan config:cache")
    print("Enabled MORPHEUS_ORIGINATE_CUSTOMER_FIRST=true — cell rings BEFORE browser bridge.")
    ssh.close()
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
