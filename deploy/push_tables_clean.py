#!/usr/bin/env python3
"""Deploy global clean table UI system."""
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
FILES = ["resources/css/app.css"]


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
    remote_tar = "/tmp/apexone-tables-clean.tar.gz"
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
python3 - <<'PY'
from pathlib import Path
css = Path('resources/css/app.css').read_text(errors='replace')
print('clean_system', 'Global table system' in css)
print('no_cell_vborder', 'border-right: none !important' in css)
print('dark_header', '#312e81' in css and '#e9d5ff' in css)
print('taller_scroll', '72vh' in css)
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
