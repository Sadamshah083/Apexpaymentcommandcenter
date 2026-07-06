#!/usr/bin/env python3
import base64, sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import connect, sudo_run

SCRIPT = open(ROOT / 'deploy' / 'sip_digest_remote.py').read()
enc = base64.b64encode(SCRIPT.encode()).decode()
ssh = connect()
print(sudo_run(ssh, f"echo {enc} | base64 -d | python3", check=False))
ssh.close()
