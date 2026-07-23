#!/usr/bin/env python3
from __future__ import annotations

import sys
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]
NEW = {"host": "203.215.161.236", "user": "ateg", "password": "balitech1"}
REMOTE = "/var/www/apexone"
FILES = [
    "app/Services/Integrations/ZoomApiService.php",
    "app/Services/Communications/CommunicationsWebphoneService.php",
    "app/Http/Controllers/MorpheusHubController.php",
]


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(NEW["host"], username=NEW["user"], password=NEW["password"], timeout=40)

    sys.path.insert(0, str(ROOT))
    import deploy._ssh as ssh_mod
    from deploy._ssh import sudo_run, upload_files

    ssh_mod.HOST = NEW["host"]
    ssh_mod.USER = NEW["user"]
    ssh_mod.PASSWORD = NEW["password"]
    ssh_mod.REMOTE_APP = REMOTE

    print(f"Uploading {len(FILES)} files...")
    upload_files(ssh, [(ROOT / rel, rel) for rel in FILES], app_root=REMOTE)
    files_chown = " ".join(f"{REMOTE}/{f}" for f in FILES)
    inner = f"""
set -e
cd {REMOTE}
chown www-data:www-data {files_chown}

# Database cache serializes every presence/originate under MySQL load — use file cache.
if grep -q '^CACHE_STORE=database' .env; then
  sed -i 's/^CACHE_STORE=database/CACHE_STORE=file/' .env
  echo "CACHE_STORE switched to file"
fi

php artisan config:clear
php artisan cache:clear
php artisan view:clear
php artisan route:clear
php -l app/Services/Integrations/ZoomApiService.php
php -l app/Services/Communications/CommunicationsWebphoneService.php
grep -n "Fast path: browser line\\|webphone.prepare.ok\\|ReleaseSessionLock::now" \\
  app/Services/Integrations/ZoomApiService.php \\
  app/Services/Communications/CommunicationsWebphoneService.php \\
  app/Http/Controllers/MorpheusHubController.php | head -20
grep -E '^CACHE_STORE=' .env
echo DONE_FAST_ORIGINATE
"""
    out = sudo_run(ssh, inner)
    print(out.encode("ascii", "replace").decode("ascii"))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
