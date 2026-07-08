#!/usr/bin/env python3
"""Deploy SaraPhone WebRTC integration to production."""

from __future__ import annotations

import io
import shlex
import sys
import tarfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, sudo_run_batch

FILES = [
    "app/Http/Controllers/MorpheusHubController.php",
    "app/Services/Communications/SaraPhoneService.php",
    "app/Services/Communications/CommunicationsWebphoneService.php",
    "app/Services/Communications/ZoomClickToCallService.php",
    "app/Services/Integrations/ZoomApiService.php",
    "config/integrations.php",
    "routes/morpheus-communications.php",
    "resources/css/communications-inbox.css",
    "resources/views/layouts/saraphone.blade.php",
    "resources/views/communications/saraphone/index.blade.php",
    "resources/views/communications/partials/webphone-panel.blade.php",
]

SARAPHONE_DIR = ROOT / "public" / "saraphone"
BUILD_DIR = ROOT / "public" / "build"


def build_archive() -> bytes:
    if not SARAPHONE_DIR.is_dir():
        raise FileNotFoundError("public/saraphone — run: git clone https://github.com/gmaruzz/saraphone.git public/saraphone")

    buffer = io.BytesIO()
    with tarfile.open(fileobj=buffer, mode="w:gz") as tar:
        for rel in FILES:
            local = ROOT / rel
            if not local.is_file():
                raise FileNotFoundError(rel)
            tar.add(local, arcname=rel.replace("\\", "/"))

        for path in SARAPHONE_DIR.rglob("*"):
            if path.is_file():
                arc = path.relative_to(ROOT).as_posix()
                tar.add(path, arcname=arc)

        if BUILD_DIR.is_dir():
            for path in BUILD_DIR.rglob("*"):
                if path.is_file():
                    tar.add(path, arcname=path.relative_to(ROOT).as_posix())

    buffer.seek(0)
    return buffer.read()


def main() -> int:
    archive = build_archive()
    print(f"Archive size: {len(archive) / 1024:.1f} KB")

    ssh = connect()
    remote_tar = "/tmp/apexone-saraphone.tar.gz"

    sftp = ssh.open_sftp()
    with sftp.file(remote_tar, "wb") as remote:
        remote.write(archive)
    sftp.close()

    paths = " ".join(shlex.quote(f"{REMOTE_APP}/{rel}") for rel in FILES)
    paths += f" {shlex.quote(f'{REMOTE_APP}/public/saraphone')}"
    if BUILD_DIR.is_dir():
        paths += f" {shlex.quote(f'{REMOTE_APP}/public/build')}"

    sudo_run_batch(ssh, [
        f"tar -xzf {remote_tar} -C {REMOTE_APP}",
        f"chown -R www-data:www-data {paths}",
        f"rm -f {remote_tar}",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan optimize:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan config:cache",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear",
        "systemctl reload php8.3-fpm",
        "systemctl reload nginx",
    ])

    checks = [
        "curl -fsS https://crm.apexonepayments.com/up",
        "curl -fsSI https://crm.apexonepayments.com/saraphone/saraphone.html | head -1",
        "curl -fsSI https://crm.apexonepayments.com/saraphone/apex-preset.js | head -1",
        "curl -fsSI https://crm.apexonepayments.com/saraphone/apex-audio.js | head -1",
        "curl -fsSI https://crm.apexonepayments.com/saraphone/apex-wss.js | head -1",
        "curl -fsSI https://crm.apexonepayments.com/admin/communications/morpheus/saraphone | head -1",
    ]
    for cmd in checks:
        _, stdout, _ = ssh.exec_command(cmd)
        print(cmd.split()[-1] + ":", stdout.read().decode(errors="replace").strip())

    ssh.close()
    print("SaraPhone deploy complete.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
