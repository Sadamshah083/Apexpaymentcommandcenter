#!/usr/bin/env python3
from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

ssh = connect()
upload_files(ssh, [(ROOT / "deploy/_probe_calls_tmp.php", "storage/app/_probe_calls_tmp.php")], app_root=REMOTE_APP)
print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php storage/app/_probe_calls_tmp.php"))
sudo_run(ssh, f"rm -f {REMOTE_APP}/storage/app/_probe_calls_tmp.php")
ssh.close()
