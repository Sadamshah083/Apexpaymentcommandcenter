#!/usr/bin/env python3
"""Deploy originate + call-logs + disposition + mic fixes to new server."""
from __future__ import annotations

import os
import shlex
import sys
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]
NEW = {
    "host": "203.215.161.236",
    "user": "ateg",
    "password": os.environ.get("NEW_DEPLOY_PASSWORD", "balitech1"),
}
REMOTE_APP = "/var/www/apexone"

FILES = [
    "app/Services/Integrations/ZoomApiService.php",
    "resources/views/communications/partials/center-dialer-hub.blade.php",
    "resources/css/comm-hub-ghl-theme.css",
    "resources/js/communications-dialer.js",
    "resources/js/communications-auto-dial.js",
    "resources/js/communications-webphone.js",
]


def main() -> int:
    sys.path.insert(0, str(ROOT))
    os.environ["DEPLOY_HOST"] = NEW["host"]
    os.environ["DEPLOY_USER"] = NEW["user"]
    os.environ["DEPLOY_PASSWORD"] = NEW["password"]
    import deploy._ssh as ssh_mod

    ssh_mod.HOST = NEW["host"]
    ssh_mod.USER = NEW["user"]
    ssh_mod.PASSWORD = NEW["password"]
    ssh_mod.REMOTE_APP = REMOTE_APP
    from deploy._ssh import upload_files

    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(NEW["host"], username=NEW["user"], password=NEW["password"], timeout=30)

    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    print(f"Uploading {len(pairs)} files...")
    upload_files(ssh, pairs, app_root=REMOTE_APP)

    cmd = f"""
set -e
cd {REMOTE_APP}
./node_modules/.bin/vite build > /tmp/vite-originate-fix.log 2>&1
echo BUILD:$?
tail -n 12 /tmp/vite-originate-fix.log
chown -R www-data:www-data {REMOTE_APP}/public/build
sudo -u www-data php artisan view:clear
sudo -u www-data php artisan config:clear
# Clear Morpheus circuit so dialing recovers immediately
sudo -u www-data php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
app(App\\Services\\Integrations\\MorpheusCircuitBreaker::class)->reset();
echo "circuit_reset\\n";
'
systemctl restart php8.3-fpm
# Sanity checks
grep -n "Soft reopen for dialing" app/Services/Integrations/ZoomApiService.php | head
grep -n "comm:dial-started" resources/js/communications-webphone.js | head
grep -n "always hydrate" resources/js/communications-dialer.js | head
python3 - <<'PY'
from pathlib import Path
import json
m=json.loads(Path('public/build/manifest.json').read_text())
for k,v in m.items():
    if 'communications' in k and k.endswith('.js'):
        p=Path('public/build')/v['file']
        t=p.read_text(errors='replace')
        print(k, 'dial_started=', 'comm:dial-started' in t, 'hydrate=', 'always hydrate' in t or 'Fast SSR shell ships empty' in t)
PY
echo DONE
"""
    full = f"echo {shlex.quote(NEW['password'])} | sudo -S -p '' bash -lc {shlex.quote(cmd)}"
    _, o, e = ssh.exec_command(full, timeout=300)
    print((o.read() + e.read()).decode(errors="replace"))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
