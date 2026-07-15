#!/usr/bin/env python3
"""Deploy agent disposition modal close fix (Turbo orphan + reopen guard)."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

FILES = [
    "resources/js/communications-auto-dial.js",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    if not pairs:
        print("No files to upload", file=sys.stderr)
        return 1

    ssh = connect()
    upload_files(ssh, pairs, REMOTE_APP)
    print(
        sudo_run(
            ssh,
            f"""
cd {REMOTE_APP} && npm run build > /tmp/vite-disposition-close.log 2>&1
tail -n 20 /tmp/vite-disposition-close.log
echo BUILD:$?
chown -R www-data:www-data {REMOTE_APP}/public/build
grep -n "shouldSuppressDispositionReopen\\|resolveSummaryModalElement\\|submitDispositionAction\\|dispositionSaveInFlight\\|callSummaryInit" \\
  {REMOTE_APP}/resources/js/communications-auto-dial.js | head -40
""",
            check=False,
        )
    )
    ssh.close()
    print("Disposition close fix deployed. Agents: Ctrl+F5 on the dialer.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
