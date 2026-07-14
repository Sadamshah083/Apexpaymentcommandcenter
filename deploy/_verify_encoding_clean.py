#!/usr/bin/env python3
from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run

MARK = "ΓÇö"


def main() -> int:
    ssh = connect()
    print("=== phpunit ===")
    print(
        sudo_run(
            ssh,
            f"cd {REMOTE_APP} && sudo -u www-data ./vendor/bin/phpunit "
            "tests/Unit/Support/SpreadsheetImportEncodingTest.php 2>&1 | tail -50",
            check=False,
        )
    )
    print("=== mojibake check ===")
    cmd = (
        f"cd {REMOTE_APP} && "
        f"grep -R {MARK!r} public/build/assets 2>/dev/null | head -5 || echo CLEAN_BUILD; "
        f"grep -n {MARK!r} resources/views/workflows/show.blade.php "
        "resources/js/workspace-sync.js resources/js/portal-dashboard.js 2>/dev/null "
        "|| echo CLEAN_SOURCE"
    )
    print(sudo_run(ssh, cmd, check=False))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
