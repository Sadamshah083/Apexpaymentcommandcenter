#!/usr/bin/env python3
"""Deploy Morpheus call recording start/stop API wiring to NEW."""
from __future__ import annotations

import shlex
import sys
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]
NEW = {"host": "203.215.161.236", "user": "ateg", "password": "balitech1"}
REMOTE = "/var/www/apexone"
FILES = [
    "app/Services/Integrations/ZoomApiService.php",
    "app/Http/Controllers/MorpheusHubController.php",
    "routes/morpheus-communications.php",
    "resources/views/communications/partials/webphone-panel.blade.php",
    "resources/views/communications/partials/morpheus-call-controls.blade.php",
    "resources/js/communications-webphone.js",
]


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(NEW["host"], username=NEW["user"], password=NEW["password"], timeout=40)

    sys.path.insert(0, str(ROOT))
    import deploy._ssh as ssh_mod

    ssh_mod.HOST = NEW["host"]
    ssh_mod.USER = NEW["user"]
    ssh_mod.PASSWORD = NEW["password"]
    ssh_mod.REMOTE_APP = REMOTE
    from deploy._ssh import sudo_run, upload_files

    pairs = [(ROOT / rel, rel) for rel in FILES]
    missing = [str(p) for p, _ in pairs if not p.is_file()]
    if missing:
        print("Missing:", *missing, sep="\n ")
        return 1

    print(f"Uploading {len(pairs)} files...")
    upload_files(ssh, pairs, app_root=REMOTE)

    files_chown = " ".join(f"{REMOTE}/{f}" for f in FILES)
    inner = f"""
set -e
cd {REMOTE}
chown www-data:www-data {files_chown}

# Ensure API key is present (do not echo full secret)
if ! grep -q '^MORPHEUS_API_KEY=ck_' .env; then
  echo 'WARN: MORPHEUS_API_KEY missing or unexpected in .env'
fi
grep -E '^MORPHEUS_(HOST|API_KEY)=' .env | sed -E 's/(MORPHEUS_API_KEY=).{{8}}.*/\\1********/'

php -l app/Services/Integrations/ZoomApiService.php
php -l app/Http/Controllers/MorpheusHubController.php
php -l routes/morpheus-communications.php

php artisan route:clear
php artisan view:clear
php artisan config:clear

# Smoke-test Record endpoint auth via artisan (expect 404 call not found)
sudo -u www-data php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
$svc = app(App\\Services\\Integrations\\ZoomApiService::class);
$r = $svc->recordCall("00000000-0000-4000-8000-000000000001", "start");
echo "record_smoke=".json_encode($r).PHP_EOL;
if (!($r["ok"] ?? false) && str_contains(strtolower((string)($r["error"] ?? "")), "not found")) {{
  echo "AUTH_OK_ENDPOINT_REACHABLE\\n";
}} elseif (($r["ok"] ?? false)) {{
  echo "UNEXPECTED_OK\\n";
}} else {{
  echo "CHECK_RESULT\\n";
}}
'

# Rebuild frontend assets that include webphone
npm run build --silent
php artisan route:list --name=communications.morpheus.calls.record | head -5
echo DONE_RECORDING_API
"""
    print(sudo_run(ssh, inner))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
