#!/usr/bin/env python3
import sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import connect, sudo_run

ssh = connect()
checks = [
    "grep -n 'fastDialerShell' /var/www/apexone/app/Services/Communications/CommunicationsInboxService.php | head -5",
    "grep -n 'dialerExtensionsFast' /var/www/apexone/app/Services/Communications/CommunicationsAgentService.php | head -3",
    "grep -n 'pollClient' /var/www/apexone/app/Services/Integrations/ZoomApiService.php | head -3",
]
for c in checks:
    print("===", c)
    print(sudo_run(ssh, c, check=False))
ssh.close()
