#!/usr/bin/env python3
"""Deploy app-wide dark mode, sidebar restructure, dashboard charts, assigned leads."""
from __future__ import annotations

import io
import os
import shlex
import sys
import tarfile
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
    "config/pagination.php",
    "app/Services/Workflow/WorkflowDashboardService.php",
    "app/Http/Controllers/WorkflowController.php",
    "resources/css/app.css",
    "resources/js/theme.js",
    "resources/views/layouts/partials/sidebar-shell.blade.php",
    "resources/views/layouts/partials/sidebar-nav-admin.blade.php",
    "resources/views/layouts/partials/topnav.blade.php",
    "resources/views/admin/dashboard/index.blade.php",
    "resources/views/admin/dashboard/partials/imports-panel.blade.php",
    "resources/views/workflows/index.blade.php",
    "public/images/apexone-logo-dark.png",
]


def main() -> int:
    build = ROOT / "public" / "build"
    if not build.exists():
        raise SystemExit("Run npm run build first")

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

    buf = io.BytesIO()
    with tarfile.open(fileobj=buf, mode="w:gz") as tar:
        tar.add(build, arcname="public/build")
    buf.seek(0)
    remote_tar = "/tmp/apexone-build-ui-dark.tar.gz"
    sftp = ssh.open_sftp()
    sftp.putfo(buf, remote_tar)
    sftp.close()

    inner = f"""
set -e
cd {REMOTE}
tar -xzf {remote_tar} -C {REMOTE}
rm -f {remote_tar}
chown -R www-data:www-data public/build public/images/apexone-logo-dark.png
sudo -u www-data php artisan view:clear
sudo -u www-data php artisan config:clear
python3 - <<'PY'
from pathlib import Path
css = Path('resources/css/app.css').read_text(errors='replace')
nav = Path('resources/views/layouts/partials/sidebar-nav-admin.blade.php').read_text(errors='replace')
shell = Path('resources/views/layouts/partials/sidebar-shell.blade.php').read_text(errors='replace')
print('dark_logo', Path('public/images/apexone-logo-dark.png').exists())
print('sidebar_theme_toggle', 'app-sidebar-theme-toggle' in shell)
print('logo_dark_class', 'app-sidebar-logo--dark' in shell)
print('nav_imported', 'Imported Leads' in nav)
print('nav_assigned', 'Assigned Leads' in nav)
print('nav_no_team_perf', 'Team performance' not in nav)
print('dark_tables', 'Kill remaining white surfaces' in css)
print('page_size_50', '50' in Path('config/pagination.php').read_text())
print('dash_pie', 'pipelinePieChart' in Path('resources/views/admin/dashboard/index.blade.php').read_text())
PY
echo DONE
"""
    cmd = f"echo {shlex.quote(OLD['password'])} | sudo -S -p '' bash -lc {shlex.quote(inner)}"
    _, o, e = ssh.exec_command(cmd, timeout=180)
    sys.stdout.write(o.read().decode(errors="replace"))
    err = e.read().decode(errors="replace")
    if err.strip():
        sys.stdout.write("---stderr---\n" + err[-2000:])
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
