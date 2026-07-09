#!/usr/bin/env python3
import sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import REMOTE_APP, connect, sudo_run
ssh = connect()
cmd = f"grep -rn 'Destination leg\\|never rang\\|normal clearing' {REMOTE_APP}/app {REMOTE_APP}/resources 2>/dev/null | head -30"
print(sudo_run(ssh, cmd, check=False))
ssh.close()
