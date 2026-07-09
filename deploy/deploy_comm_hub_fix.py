#!/usr/bin/env python3
"""Deploy Communications Hub outbound dial fix to production (no git)."""
from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, sudo_run_batch, upload_files

FILES = [
    "app/Services/Integrations/ZoomApiService.php",
    "app/Services/Integrations/MorpheusCircuitBreaker.php",
    "app/Services/Communications/CommunicationsAgentService.php",
    "app/Services/Communications/CommunicationsInboxService.php",
    "app/Services/Communications/CommunicationsWebphoneService.php",
    "app/Services/Communications/ZoomClickToCallService.php",
    "app/Services/Communications/MorpheusCallEventService.php",
    "app/Services/Communications/CommunicationsCallHistoryService.php",
    "app/Http/Controllers/MorpheusHubController.php",
    "app/Support/MorpheusSipIdentity.php",
    "bootstrap/app.php",
    "config/integrations.php",
    "routes/web.php",
    "routes/morpheus-communications.php",
    "resources/js/communications-dialer.js",
    "resources/js/communications-webphone.js",
    "resources/css/comm-hub-atomic.css",
    "resources/views/communications/partials/dialer-form.blade.php",
    "resources/views/communications/partials/webphone-panel.blade.php",
    "resources/views/communications/partials/center-dialer-hub.blade.php",
    "resources/views/components/communications/molecules/workflow-stepper.blade.php",
]


def main() -> int:
    pairs = [(ROOT / rel, rel.replace("\\", "/")) for rel in FILES if (ROOT / rel).is_file()]
    missing = [rel for rel in FILES if not (ROOT / rel).is_file()]
    if missing:
        print("Missing files:", ", ".join(missing), file=sys.stderr)
        return 1

    ssh = connect()
    print(f"Uploading {len(pairs)} files...")
    upload_files(ssh, pairs, app_root=REMOTE_APP)

    print("Building frontend assets on server...")
    sudo_run_batch(ssh, [
        f"cd {REMOTE_APP} && npm ci --ignore-scripts && npm run build",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan config:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan config:cache",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan route:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan route:cache",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear",
        f"chown -R www-data:www-data {REMOTE_APP}/public/build",
        "systemctl reload php8.3-fpm",
        "systemctl reload nginx",
    ])

    print("Health:", sudo_run(ssh, f"curl -fsS https://crm.apexonepayments.com/up | head -5", check=False))
    ssh.close()
    print("Deploy complete.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
