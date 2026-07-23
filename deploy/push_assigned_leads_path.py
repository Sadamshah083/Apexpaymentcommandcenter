#!/usr/bin/env python3
"""Deploy Assigned Leads path /admin/assigned-leads."""
from __future__ import annotations

import os
import shlex
import sys
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]
OLD = {
    "host": "203.215.160.44",
    "user": "issac",
    "password": os.environ.get("OLD_DEPLOY_PASSWORD", "SadamShah123"),
}
REMOTE = "/var/www/apexone"
FILES = [
    "routes/web.php",
    "app/Http/Controllers/WorkflowController.php",
    "config/admin_modules.php",
    "resources/views/layouts/partials/sidebar-nav-admin.blade.php",
    "resources/views/workflows/index.blade.php",
    "resources/views/admin/dashboard/partials/imports-panel.blade.php",
]


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(OLD["host"], username=OLD["user"], password=OLD["password"], timeout=35)

    sys.path.insert(0, str(ROOT))
    import deploy._ssh as ssh_mod

    ssh_mod.HOST = OLD["host"]
    ssh_mod.USER = OLD["user"]
    ssh_mod.PASSWORD = OLD["password"]
    ssh_mod.REMOTE_APP = REMOTE
    from deploy._ssh import upload_files

    upload_files(ssh, [(ROOT / rel, rel) for rel in FILES], app_root=REMOTE)

    inner = f"""
set -e
cd {REMOTE}
sudo -u www-data php artisan route:clear
sudo -u www-data php artisan config:clear
sudo -u www-data php artisan view:clear
php artisan route:list --name=assigned-leads --columns=method,uri,name 2>/dev/null || true
python3 - <<'PY'
from pathlib import Path
web = Path('routes/web.php').read_text(errors='replace')
side = Path('resources/views/layouts/partials/sidebar-nav-admin.blade.php').read_text(errors='replace')
print('route_uri', "assigned-leads" in web and "admin.assigned-leads" in web)
print('sidebar', "route('admin.assigned-leads')" in side)
PY
echo DONE
"""
    cmd = f"echo {shlex.quote(OLD['password'])} | sudo -S -p '' bash -lc {shlex.quote(inner)}"
    _, o, e = ssh.exec_command(cmd, timeout=120)
    sys.stdout.write(o.read().decode(errors="replace"))
    err = e.read().decode(errors="replace")
    if err.strip():
        sys.stdout.write("---stderr---\n" + err[-2000:])
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
