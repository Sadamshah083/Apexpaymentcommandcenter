#!/usr/bin/env python3
from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

FILES = [
    "resources/js/workspace-sync.js",
    "resources/js/portal-dashboard.js",
    "resources/views/workflows/show.blade.php",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    upload_files(ssh, pairs, app_root=REMOTE_APP)

    # Confirm source on server has no mojibake before build
    print(
        sudo_run(
            ssh,
            f"cd {REMOTE_APP} && "
            "grep -n $'\\xCE\\x93\\xC3\\x87\\xC3\\xB6' resources/js/workspace-sync.js "
            "resources/js/portal-dashboard.js resources/views/workflows/show.blade.php "
            "|| echo SOURCE_CLEAN",
            check=False,
        )
    )

    print("Rebuilding Vite assets...")
    print(
        sudo_run(
            ssh,
            f"cd {REMOTE_APP} && sudo -u www-data bash -lc 'export PYTHONIOENCODING=utf-8; npm run build'",
            check=False,
        )[-3000:]
    )

    print(
        sudo_run(
            ssh,
            f"cd {REMOTE_APP} && "
            "grep -R $'\\xCE\\x93\\xC3\\x87\\xC3\\xB6' public/build/assets 2>/dev/null | head -3 "
            "|| echo BUILD_CLEAN",
            check=False,
        )
    )
    sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear", check=False)
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
