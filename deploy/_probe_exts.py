#!/usr/bin/env python3
from __future__ import annotations

import shlex
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import PASSWORD, REMOTE_APP, connect, upload_files


def main() -> int:
    ssh = connect()
    upload_files(ssh, [(ROOT / "scripts/_probe_morpheus_exts.php", "scripts/_probe_morpheus_exts.php")])
    full = (
        f"echo {shlex.quote(PASSWORD)} | sudo -S bash -lc "
        f"{shlex.quote(f'cd {REMOTE_APP} && sudo -u www-data php scripts/_probe_morpheus_exts.php')}"
    )
    _, out, err = ssh.exec_command(full, timeout=60)
    print(out.read().decode(errors="replace"))
    print(err.read().decode(errors="replace")[-1500:])
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
