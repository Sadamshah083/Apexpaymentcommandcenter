#!/usr/bin/env python3
"""Deploy Communications Hub calling hotfix to production."""

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
    "app/Services/Communications/CommunicationsAgentService.php",
    "app/Services/Communications/CommunicationsInboxService.php",
    "app/Services/Communications/CommunicationsAccessService.php",
    "app/Services/Communications/CommunicationsCallHistoryService.php",
    "app/Services/Communications/MorpheusHubService.php",
    "app/Services/Communications/CommunicationsWebphoneService.php",
    "app/Services/Communications/ZoomClickToCallService.php",
    "app/Services/Integrations/MorpheusCircuitBreaker.php",
    "app/Services/Integrations/ZoomApiService.php",
    "app/Support/MorpheusSipIdentity.php",
    "app/Services/Communications/CommunicationsDataService.php",
    "app/Http/Controllers/CommunicationsHubController.php",
    "app/Http/Controllers/WorkspaceAuthController.php",
    "app/Http/Middleware/AdminPortalMiddleware.php",
    "app/Http/Middleware/MarketerPortalMiddleware.php",
    "app/Support/AdminModules.php",
    "config/admin_modules.php",
    "config/integrations.php",
    "routes/morpheus-communications.php",
    "resources/js/app.js",
    "resources/css/communications-inbox.css",
    "resources/js/communications-dialer.js",
    "resources/js/communications-webphone.js",
    "resources/views/communications/calls/index.blade.php",
    "resources/views/communications/dialer/index.blade.php",
    "resources/views/communications/inbox/index.blade.php",
    "resources/views/communications/inbox/partials/nav.blade.php",
    "resources/views/communications/inbox/partials/nav-item.blade.php",
    "resources/views/communications/inbox/partials/nav-icon.blade.php",
    "resources/views/communications/inbox/partials/toolbar.blade.php",
    "resources/views/communications/inbox/partials/main.blade.php",
    "resources/views/communications/inbox/partials/tools.blade.php",
    "resources/views/communications/inbox/partials/panels/dialer.blade.php",
    "resources/views/communications/inbox/partials/rail-dialer-compact.blade.php",
    "resources/views/communications/partials/center-dialer-hub.blade.php",
    "resources/views/communications/partials/dialer-form.blade.php",
    "resources/views/communications/inbox/partials/panels/settings.blade.php",
    "resources/views/communications/inbox/partials/panels/agents.blade.php",
    "resources/views/communications/partials/webphone-panel.blade.php",
    "resources/views/communications/partials/webphone-floating-popup.blade.php",
    "deploy/nginx-apexone.conf",
]

BUILD_DIR = ROOT / "public" / "build"


def build_archive() -> bytes:
    buffer = io.BytesIO()
    with tarfile.open(fileobj=buffer, mode="w:gz") as tar:
        for rel in FILES:
            local = ROOT / rel
            if not local.is_file():
                raise FileNotFoundError(rel)
            tar.add(local, arcname=rel.replace("\\", "/"))

        if not BUILD_DIR.is_dir():
            raise FileNotFoundError("public/build — run npm run build first")

        for path in BUILD_DIR.rglob("*"):
            if path.is_file():
                arc = path.relative_to(ROOT).as_posix()
                tar.add(path, arcname=arc)

    buffer.seek(0)
    return buffer.read()


def main() -> int:
    archive = build_archive()
    print(f"Archive size: {len(archive) / 1024:.1f} KB")

    ssh = connect()
    remote_tar = "/tmp/apexone-calling-hotfix.tar.gz"

    sftp = ssh.open_sftp()
    with sftp.file(remote_tar, "wb") as remote:
        remote.write(archive)
    sftp.close()

    paths = " ".join(shlex.quote(f"{REMOTE_APP}/{rel}") for rel in FILES)
    paths += f" {shlex.quote(f'{REMOTE_APP}/public/build')}"

    sudo_run_batch(ssh, [
        f"tar -xzf {remote_tar} -C {REMOTE_APP}",
        f"chown -R www-data:www-data {paths}",
        f"rm -f {remote_tar}",
        f"rm -f {REMOTE_APP}/public/hot",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan config:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan route:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan config:cache",
        f"cp {REMOTE_APP}/deploy/nginx-apexone.conf /etc/nginx/sites-available/apexone",
        "nginx -t && systemctl reload nginx",
        "systemctl reload php8.3-fpm",
        f"grep -q '^MORPHEUS_WEBPHONE_AUTO_ANSWER=' {REMOTE_APP}/.env "
        f"&& sed -i 's/^MORPHEUS_WEBPHONE_AUTO_ANSWER=.*/MORPHEUS_WEBPHONE_AUTO_ANSWER=true/' {REMOTE_APP}/.env "
        f"|| echo 'MORPHEUS_WEBPHONE_AUTO_ANSWER=true' >> {REMOTE_APP}/.env",
        f"grep -q '^MORPHEUS_SARAPHONE_ENABLED=' {REMOTE_APP}/.env "
        f"&& sed -i 's/^MORPHEUS_SARAPHONE_ENABLED=.*/MORPHEUS_SARAPHONE_ENABLED=false/' {REMOTE_APP}/.env "
        f"|| echo 'MORPHEUS_SARAPHONE_ENABLED=false' >> {REMOTE_APP}/.env",
    ])

    _, stdout, _ = ssh.exec_command("curl -fsS https://crm.apexonepayments.com/up")
    health = stdout.read().decode(errors="replace").strip()
    print("Health:", health or "(empty)")

    _, stdout, _ = ssh.exec_command(
        "curl -fsS https://crm.apexonepayments.com/build/manifest.json | head -c 400"
    )
    manifest = stdout.read().decode(errors="replace").strip()
    print("Manifest snippet:", manifest[:300])

    _, stdout, _ = ssh.exec_command(
        "python3 -c \"import json; m=json.load(open('/var/www/apexone/public/build/manifest.json')); "
        "js=[v['file'] for k,v in m.items() if 'communications-webphone' in k or 'communications-dialer' in k]; "
        "print('\\n'.join(js))\""
    )
    assets = stdout.read().decode(errors="replace").strip()
    print("Calling assets:", assets)

    for line in assets.splitlines():
        if not line.strip():
            continue
        path = line.strip().lstrip("/")
        if not path.startswith("build/"):
            path = f"build/{path}"
        _, stdout, _ = ssh.exec_command(f"curl -fsSI https://crm.apexonepayments.com/{path} | head -1")
        print(f"  {path}: {stdout.read().decode().strip()}")

    ssh.close()
    print("Deploy complete.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
