#!/usr/bin/env python3
from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run

ssh = connect()
print(sudo_run(ssh, f"cd {REMOTE_APP} && npm run build > /tmp/vite-build.log 2>&1; echo EXIT:$?"))
print(sudo_run(ssh, "wc -c /tmp/vite-build.log; ls -la /var/www/apexone/public/build/assets 2>/dev/null | head -5"))
print(sudo_run(ssh, "grep -a 'built in' /tmp/vite-build.log | head -3 || true"))
ssh.close()
