#!/usr/bin/env python3
from __future__ import annotations
import sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import REMOTE_APP, connect, sudo_run

ssh = connect()
print(sudo_run(ssh, f"grep -n connectedIdleSec {REMOTE_APP}/app/Services/Communications/CallMonitoringService.php; grep -n touchCallLogEnded {REMOTE_APP}/app/Http/Controllers/MorpheusHubController.php | head -3", check=False))
ssh.close()
