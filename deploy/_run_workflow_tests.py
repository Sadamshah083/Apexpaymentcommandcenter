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
    cmd = (
        f"cd {REMOTE_APP} && sudo -u www-data ./vendor/bin/phpunit "
        "--filter=WorkflowServiceTest tests/Unit/Services/WorkflowServiceTest.php"
    )
    full = f"echo {shlex.quote(PASSWORD)} | sudo -S bash -lc {shlex.quote(cmd)}"
    _, stdout, stderr = ssh.exec_command(full)
    code = stdout.channel.recv_exit_status()
    print(stdout.read().decode(errors="replace"))
    err = stderr.read().decode(errors="replace")
    if err:
        print(err)
    ssh.close()
    return code


if __name__ == "__main__":
    raise SystemExit(main())
