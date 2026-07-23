#!/usr/bin/env python3
from __future__ import annotations

import sys
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]
NEW = {"host": "203.215.161.236", "user": "ateg", "password": "balitech1"}
REMOTE = "/var/www/apexone"
FILES = [
    "resources/views/layouts/admin.blade.php",
    "resources/views/layouts/portal.blade.php",
    "resources/views/layouts/partials/google-fonts.blade.php",
    "resources/views/layouts/partials/critical-head.blade.php",
    "resources/views/auth/login_admin.blade.php",
    "resources/views/auth/login_portal.blade.php",
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
php artisan view:clear
npm run build --silent
test -f public/build/manifest.json
php -r 'echo json_encode(json_decode(file_get_contents("public/build/manifest.json"), true)["resources/js/app.js"]["file"] ?? "missing"), PHP_EOL;'
ls -1 public/build/assets/app-*.js public/build/assets/app-*.css | tail -6
curl -s -o /dev/null -w 'admin_login=%{{http_code}} t=%{{time_total}}\\n' https://crm.apexonepayments.com/admin/login
curl -s -o /dev/null -w 'portal_login=%{{http_code}} t=%{{time_total}}\\n' https://crm.apexonepayments.com/portal/login
# Ensure HTML paints (has body + title)
curl -s https://crm.apexonepayments.com/admin/login | head -c 800 | tr '\\n' ' '
echo
echo DONE_BUILD_FCP
"""
    out = sudo_run(ssh, inner)
    print(out.encode("ascii", "replace").decode("ascii"))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
