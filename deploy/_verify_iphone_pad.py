#!/usr/bin/env python3
from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run  # noqa: E402


def main() -> int:
    ssh = connect()
    print("blade:", sudo_run(ssh, f"grep -c ghl-dialer-key__letters {REMOTE_APP}/resources/views/communications/partials/dialer-form.blade.php"))
    print("css src:", sudo_run(ssh, f"grep -c 'iPhone-style dial pad' {REMOTE_APP}/resources/css/comm-hub-ghl-theme.css"))
    print("built:", sudo_run(ssh, f"grep -l ghl-dialer-key__letters {REMOTE_APP}/public/build/assets/*.css | head -3 || echo MISSING"))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
