#!/usr/bin/env python3
"""Set originate method to direct /calls/originate (not click-to-call)."""
from __future__ import annotations
import sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import REMOTE_APP, connect, set_env_vars, sudo_run

ENV = {
    "MORPHEUS_ORIGINATE_METHOD": "click-to-call",
    "MORPHEUS_ORIGINATE_CUSTOMER_FIRST": "false",
    "MORPHEUS_WEBPHONE_DIAL_MODE": "sip",
    "MORPHEUS_WEBPHONE_AUTO_ANSWER": "false",
}

def main() -> int:
    ssh = connect()
    set_env_vars(ssh, ENV)
    sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php artisan config:clear && sudo -u www-data php artisan config:cache")
    print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php -r \"require 'vendor/autoload.php'; \\$a=require 'bootstrap/app.php'; \\$a->make(Illuminate\\\\Contracts\\\\Console\\\\Kernel::class)->bootstrap(); echo config('integrations.morpheus.originate_method');\""))
    ssh.close()
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
