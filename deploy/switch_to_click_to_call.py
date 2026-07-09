#!/usr/bin/env python3
"""Switch browser dial to Morpheus click-to-call (proven path) + auto-answer."""
from __future__ import annotations
import base64
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import REMOTE_APP, connect, set_env_vars, sudo_run, sudo_run_batch

CLEAR_PHP = r"""<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$api = app(App\Services\Integrations\ZoomApiService::class);
$ext = '1020';
$api->clearExtensionForOutboundDial($ext, kickSip: true);
sleep(2);
echo json_encode([
    'dial_mode' => config('integrations.morpheus.webphone_dial_mode'),
    'auto_answer' => config('integrations.morpheus.webphone_auto_answer'),
    'originate_method' => config('integrations.morpheus.originate_method'),
], JSON_PRETTY_PRINT);
"""

def main() -> int:
    ssh = connect()
    set_env_vars(ssh, {
        "MORPHEUS_WEBPHONE_DIAL_MODE": "api",
        "MORPHEUS_WEBPHONE_AUTO_ANSWER": "true",
        "MORPHEUS_ORIGINATE_METHOD": "click-to-call",
    })
    sudo_run_batch(ssh, [
        f"cd {REMOTE_APP} && sudo -u www-data php artisan config:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan config:cache",
    ])
    enc = base64.b64encode(CLEAR_PHP.encode()).decode()
    print(sudo_run(ssh, f"cd {REMOTE_APP} && echo {enc} | base64 -d | sudo -u www-data php"))
    ssh.close()
    print("\nDone — dial uses click-to-call API; browser auto-answers agent leg.")
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
