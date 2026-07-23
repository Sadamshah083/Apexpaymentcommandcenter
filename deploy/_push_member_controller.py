#!/usr/bin/env python3
"""Upload latest WorkspaceMemberController to old server."""
from __future__ import annotations

import os
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
os.environ.setdefault("DEPLOY_PASSWORD", "SadamShah123")

import deploy._ssh as m

m.HOST = "203.215.160.44"
m.USER = "issac"
m.PASSWORD = "SadamShah123"
m.REMOTE_APP = "/var/www/apexone"
from deploy._ssh import connect, sudo_run, upload_files

ssh = connect()
try:
    upload_files(
        ssh,
        [(ROOT / "app/Http/Controllers/WorkspaceMemberController.php", "app/Http/Controllers/WorkspaceMemberController.php")],
        app_root="/var/www/apexone",
    )
    print(sudo_run(ssh, "cd /var/www/apexone && php -l app/Http/Controllers/WorkspaceMemberController.php"))
finally:
    ssh.close()
print("DONE")
