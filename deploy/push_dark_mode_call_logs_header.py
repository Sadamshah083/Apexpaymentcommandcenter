#!/usr/bin/env python3
"""Deploy call-log paging, app-wide dark mode, compact dialer header."""
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
    "app/Services/Communications/CommunicationsCallHistoryService.php",
    "app/Services/Communications/CommunicationsInboxService.php",
    "app/Http/Controllers/CommunicationsHubController.php",
    "resources/js/theme.js",
    "resources/js/app.js",
    "resources/js/communications-dialer.js",
    "resources/css/app.css",
    "resources/css/comm-hub-ghl-theme.css",
    "resources/css/comm-hub-ui-polish.css",
    "resources/views/layouts/admin.blade.php",
    "resources/views/layouts/portal.blade.php",
    "resources/views/layouts/partials/sidebar-state-boot.blade.php",
    "resources/views/layouts/partials/topnav.blade.php",
    "resources/views/communications/partials/center-dialer-hub.blade.php",
    "resources/views/communications/inbox/partials/toolbar-dialer.blade.php",
    "config/integrations.php",
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
    remote_tar = "/tmp/apexone-build-theme.tar.gz"
    sftp = ssh.open_sftp()
    sftp.putfo(buf, remote_tar)
    sftp.close()

    inner = f"""
set -e
cd {REMOTE}
tar -xzf {remote_tar} -C {REMOTE}
rm -f {remote_tar}
chown -R www-data:www-data public/build
sudo -u www-data php artisan view:clear
sudo -u www-data php artisan config:clear
python3 - <<'PY'
from pathlib import Path
print('pageForHub', 'pageForHub' in Path('app/Services/Communications/CommunicationsCallHistoryService.php').read_text())
print('ssr_first30', 'paginateDialerCallLogs' in Path('app/Services/Communications/CommunicationsInboxService.php').read_text())
print('theme_js', Path('resources/js/theme.js').exists())
print('theme_toggle_topnav', 'data-theme-toggle' in Path('resources/views/layouts/partials/topnav.blade.php').read_text())
print('dark_css', 'theme-dark' in Path('resources/css/app.css').read_text())
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
