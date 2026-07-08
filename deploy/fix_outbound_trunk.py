#!/usr/bin/env python3
"""Enable contactivity tech prefix + deploy outbound dial fix."""
from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, set_env_vars, sudo_run, sudo_run_batch, upload_files

FILES = [
    "app/Services/Integrations/ZoomApiService.php",
    "app/Services/Communications/ZoomClickToCallService.php",
    "app/Services/Communications/CommunicationsAgentService.php",
    "app/Http/Controllers/MorpheusHubController.php",
    "config/integrations.php",
    "resources/js/communications-webphone.js",
    "resources/js/communications-dialer.js",
    "resources/css/communications-inbox.css",
    "resources/views/communications/partials/webphone-panel.blade.php",
    "resources/views/communications/partials/webphone-floating-popup.blade.php",
]

ENV = {
    "MORPHEUS_OUTBOUND_PREFIX": "",
    "MORPHEUS_RING_TIMEOUT": "120",
    "MORPHEUS_ORIGINATE_METHOD": "click-to-call",
    "MORPHEUS_ORIGINATE_CUSTOMER_FIRST": "false",
}


def main() -> int:
    pairs = [(ROOT / rel, rel.replace("\\", "/")) for rel in FILES]
    ssh = connect()
    print("Setting production env (tech prefix + ring timeout)...")
    set_env_vars(ssh, ENV)
    print(f"Uploading {len(pairs)} files...")
    upload_files(ssh, pairs, app_root=REMOTE_APP)
    print("Building + clearing caches...")
    out = sudo_run_batch(ssh, [
        f"cd {REMOTE_APP} && npm ci --prefer-offline --no-audit 2>/dev/null || npm install --no-audit",
        f"cd {REMOTE_APP} && npm run build",
        f"cd {REMOTE_APP} && php artisan config:clear && php artisan cache:clear && php artisan view:clear",
        "systemctl reload php8.3-fpm 2>/dev/null || true",
    ])
    print(out.encode("ascii", errors="replace").decode("ascii"))
    ssh.close()
    print("Done.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
