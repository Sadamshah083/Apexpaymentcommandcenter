#!/usr/bin/env python3
"""Dump recent Laravel/nginx 500s for assign-leads."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run


def main() -> int:
    ssh = connect()
    print(
        sudo_run(
            ssh,
            f"""
tail -n 80 {REMOTE_APP}/storage/logs/laravel.log 2>/dev/null | sed -n '/ERROR\\|Exception\\|assign-leads\\|setterTeamMemberMap\\|WorkflowAssignmentRoles/,$p' | tail -n 80
echo '----'
ls -lt {REMOTE_APP}/storage/logs/ | head -5
LATEST=$(ls -t {REMOTE_APP}/storage/logs/laravel*.log 2>/dev/null | head -1)
echo "LATEST=$LATEST"
if [ -n "$LATEST" ]; then
  tail -n 120 "$LATEST" | tr '\\r' '\\n' | tail -n 120
fi
""",
            check=False,
        )
    )
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
