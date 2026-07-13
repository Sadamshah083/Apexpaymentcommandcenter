#!/usr/bin/env python3
from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run  # noqa: E402


def main() -> int:
    ssh = connect()
    print(sudo_run(ssh, f"sed -n '1080,1160p' {REMOTE_APP}/app/Services/Integrations/ZoomApiService.php"))
    print("--- md5 local vs remote ---")
    print(sudo_run(ssh, f"md5sum {REMOTE_APP}/app/Services/Integrations/ZoomApiService.php"))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
