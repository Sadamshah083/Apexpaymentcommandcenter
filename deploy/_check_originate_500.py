#!/usr/bin/env python3
from __future__ import annotations

import shlex
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import PASSWORD, REMOTE_APP, connect


def main() -> int:
    ssh = connect()
    cmd = f"""
cd {REMOTE_APP}
echo '=== latest laravel log ==='
sudo -u www-data tail -n 120 storage/logs/laravel.log 2>/dev/null | tail -n 120
echo
echo '=== grep originate/afterResponse ==='
sudo -u www-data grep -n -E 'originate|afterResponse|Error|Exception' storage/logs/laravel.log 2>/dev/null | tail -n 40
"""
    full = f"echo {shlex.quote(PASSWORD)} | sudo -S bash -lc {shlex.quote(cmd)}"
    _, out, err = ssh.exec_command(full, timeout=30)
    print(out.read().decode(errors="replace"))
    e = err.read().decode(errors="replace")
    if e.strip():
        print(e[-1500:])
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
