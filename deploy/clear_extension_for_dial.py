#!/usr/bin/env python3
"""Clear zombie calls and kick SIP on production for an extension."""
from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run

EXT = sys.argv[1] if len(sys.argv) > 1 else "1020"
KICK = "--kick" if len(sys.argv) < 3 or sys.argv[2] != "no-kick" else ""

php = (ROOT / "scripts" / "clear_extension_for_dial.php").read_text(encoding="utf-8")

ssh = connect()
remote = f"{REMOTE_APP}/scripts/clear_extension_for_dial.php"
tmp = "/tmp/clear-ext.php"
sftp = ssh.open_sftp()
with sftp.file(tmp, "w") as f:
    f.write(php)
sftp.close()
sudo_run(ssh, f"cp {tmp} {remote} && chown www-data:www-data {remote}")
print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php scripts/clear_extension_for_dial.php {EXT} {KICK}".strip(), check=False))
ssh.close()
