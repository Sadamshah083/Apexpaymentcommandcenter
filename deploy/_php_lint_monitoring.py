#!/usr/bin/env python3
from pathlib import Path
import sys
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import REMOTE_APP, connect, sudo_run
ssh = connect()
for f in [
    "app/Services/Communications/MorpheusCallEventService.php",
    "app/Services/Communications/CallMonitoringService.php",
    "app/Http/Controllers/CallMonitoringController.php",
]:
    out = sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php -l {f} 2>&1 | tr -cd '\\11\\12\\15\\40-\\176'")
    print(out)
ssh.close()
