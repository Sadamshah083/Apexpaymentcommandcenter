#!/usr/bin/env python3
"""Run scripts/verify_click_to_call_ring.php on production."""
from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run

EXT = sys.argv[1] if len(sys.argv) > 1 else "1020"
DEST = sys.argv[2] if len(sys.argv) > 2 else "+12722001232"
POLL = sys.argv[3] if len(sys.argv) > 3 else "15"

local_script = ROOT / "scripts" / "verify_click_to_call_ring.php"
php = local_script.read_text(encoding="utf-8")

ssh = connect()
remote = f"{REMOTE_APP}/scripts/verify_click_to_call_ring.php"
tmp = "/tmp/verify-click-to-call-ring.php"
sftp = ssh.open_sftp()
with sftp.file(tmp, "w") as f:
    f.write(php)
sftp.close()

sudo_run(ssh, f"cp {tmp} {remote} && chown www-data:www-data {remote}")
cmd = f"cd {REMOTE_APP} && sudo -u www-data php scripts/verify_click_to_call_ring.php {EXT} {DEST} {POLL}"
print(sudo_run(ssh, cmd, check=False))
ssh.close()
