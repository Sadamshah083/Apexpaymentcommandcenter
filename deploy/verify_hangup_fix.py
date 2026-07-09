#!/usr/bin/env python3
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import REMOTE_APP, connect, sudo_run

ssh = connect()
checks = [
    ("no uuid wipe on Established", f"grep -n 'morpheusCallUuid = null' {REMOTE_APP}/resources/js/communications-webphone.js"),
    ("activeCallUuid helper", f"grep -c activeCallUuid {REMOTE_APP}/resources/js/communications-webphone.js"),
    ("release-extension route", f"grep release-extension {REMOTE_APP}/routes/morpheus-communications.php"),
    ("release extension url in blade", f"grep releaseExtensionUrl {REMOTE_APP}/resources/views/communications/partials/webphone-panel.blade.php"),
    ("bundle has activeCallUuid", f"grep -rl activeCallUuid {REMOTE_APP}/public/build/assets/*.js 2>/dev/null | head -1"),
    ("route list release", f"cd {REMOTE_APP} && sudo -u www-data php artisan route:list --name=release-extension 2>/dev/null | tail -3"),
]
for label, cmd in checks:
    print(f"--- {label} ---")
    print(sudo_run(ssh, cmd, check=False))
ssh.close()
