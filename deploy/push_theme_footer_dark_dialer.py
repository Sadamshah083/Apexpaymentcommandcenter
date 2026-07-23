#!/usr/bin/env python3
"""Deploy sidebar theme toggle footer + dialer dark surfaces."""
from __future__ import annotations

import io
import os
import shlex
import sys
import tarfile
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]
TARGETS = [
    {
        "label": "OLD",
        "host": "203.215.160.44",
        "user": "issac",
        "password": os.environ.get("OLD_DEPLOY_PASSWORD", "SadamShah123"),
    },
    {
        "label": "NEW",
        "host": "203.215.161.236",
        "user": "ateg",
        "password": os.environ.get("NEW_DEPLOY_PASSWORD", "balitech1"),
    },
]
REMOTE = "/var/www/apexone"
FILES = [
    "resources/views/layouts/partials/sidebar-shell.blade.php",
    "resources/views/communications/inbox/partials/toolbar-dialer.blade.php",
    "resources/css/app.css",
    "resources/css/comm-hub-ui-polish.css",
]


def deploy_one(cfg: dict) -> None:
    print(f"\n=== {cfg['label']} {cfg['host']} ===", flush=True)
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(cfg["host"], username=cfg["user"], password=cfg["password"], timeout=35)

    sys.path.insert(0, str(ROOT))
    import deploy._ssh as ssh_mod

    ssh_mod.HOST = cfg["host"]
    ssh_mod.USER = cfg["user"]
    ssh_mod.PASSWORD = cfg["password"]
    ssh_mod.REMOTE_APP = REMOTE
    from deploy._ssh import upload_files

    upload_files(ssh, [(ROOT / rel, rel) for rel in FILES], app_root=REMOTE)

    build = ROOT / "public" / "build"
    buf = io.BytesIO()
    with tarfile.open(fileobj=buf, mode="w:gz") as tar:
        tar.add(build, arcname="public/build")
    buf.seek(0)
    remote_tar = "/tmp/apexone-theme-footer.tar.gz"
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
grep -n 'theme-toggle--footer' resources/views/layouts/partials/sidebar-shell.blade.php | head -2
grep -c 'ghl-comm-theme-toggle' resources/views/communications/inbox/partials/toolbar-dialer.blade.php || true
grep -n 'kill remaining white' resources/css/comm-hub-ui-polish.css | head -1
echo DONE_{cfg['label']}
"""
    cmd = f"echo {shlex.quote(cfg['password'])} | sudo -S -p '' bash -lc {shlex.quote(inner)}"
    _, o, e = ssh.exec_command(cmd, timeout=180)
    print((o.read() + e.read()).decode(errors="replace")[-2500:], flush=True)
    ssh.close()


def main() -> int:
    if not (ROOT / "public" / "build").exists():
        raise SystemExit("Run npm run build first")
    for cfg in TARGETS:
        deploy_one(cfg)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
