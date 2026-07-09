#!/usr/bin/env python3
import sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import REMOTE_APP, connect, sudo_run

cmds = [
    f"grep -n \"kickExtensionSipRegistration\\|outcome.*initiated\" {REMOTE_APP}/app/Services/Integrations/ZoomApiService.php | head -5",
    f"grep MORPHEUS_ORIGINATE_METHOD {REMOTE_APP}/.env",
    f"cd {REMOTE_APP} && sudo -u www-data php -r \"require 'vendor/autoload.php'; \\$a=require 'bootstrap/app.php'; \\$a->make(Illuminate\\\\Contracts\\\\Console\\\\Kernel::class)->bootstrap(); echo config('integrations.morpheus.originate_method');\"",
]
ssh = connect()
for c in cmds:
    print(sudo_run(ssh, c, check=False))
    print('---')
ssh.close()
