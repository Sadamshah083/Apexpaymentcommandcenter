#!/usr/bin/env python3
"""Deploy admin auto-dial, imported leads tab, and call disposition UI."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, restart_queue_workers, sudo_run, sudo_run_batch, upload_files

FILES = [
    "app/Http/Controllers/MorpheusHubController.php",
    "app/Services/Communications/MorpheusCallEventService.php",
    "app/Services/Integrations/ZoomApiService.php",
    "app/Services/Communications/DialerImportedLeadsService.php",
    "app/Services/Communications/CommunicationsInboxService.php",
    "app/Services/Communications/CommunicationsLeadLookupService.php",
    "app/Services/Communications/CommunicationsAccessService.php",
    "app/Services/Communications/CommunicationsCallHistoryService.php",
    "app/Http/Controllers/CommunicationsHubController.php",
    "config/integrations.php",
    "routes/web.php",
    "resources/js/communications-auto-dial.js",
    "resources/js/communications-dialer.js",
    "resources/js/communications-phone-notes.js",
    "resources/js/communications-webphone.js",
    "resources/js/toast.js",
    "resources/js/fast-import-nav.js",
    "resources/js/app.js",
    "resources/css/comm-hub-ui-polish.css",
    "resources/css/comm-hub-ghl-theme.css",
    "resources/css/communications-hub.css",
    "resources/css/app.css",
    "resources/js/sidebar.js",
    "resources/views/components/sidebar/link.blade.php",
    "resources/views/communications/partials/center-dialer-hub.blade.php",
    "resources/views/communications/partials/dialer-form.blade.php",
    "resources/views/communications/partials/dialer-extension-field.blade.php",
    "resources/views/communications/partials/global-line-picker.blade.php",
    "resources/views/communications/partials/call-summary-modal.blade.php",
    "resources/views/communications/partials/dialer-lead-row.blade.php",
    "resources/views/communications/partials/dialer-recording-row.blade.php",
    "resources/views/communications/partials/call-log-row.blade.php",
    "resources/views/communications/partials/phone-notes-panel.blade.php",
    "resources/views/communications/inbox/partials/toolbar-dialer.blade.php",
    "resources/views/communications/inbox/partials/panels/dialer.blade.php",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    print(f"Uploading {len(pairs)} files...")
    upload_files(ssh, pairs, app_root=REMOTE_APP)
    print("Building frontend assets on server...")
    sudo_run_batch(ssh, [
        f"cd {REMOTE_APP} && touch resources/js/communications-webphone.js resources/js/communications-dialer.js",
        f"cd {REMOTE_APP} && npm run build",
        f"chown -R www-data:www-data {REMOTE_APP}/public/build",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan route:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan config:clear",
    ])
    try:
        restart_queue_workers(ssh)
    except RuntimeError as exc:
        print(f"Warning: queue restart skipped ({exc})")
    print(sudo_run(ssh, f"curl -fsS http://203.215.160.44/admin/login -o /dev/null -w '%{{http_code}}'"))
    ssh.close()
    print("Admin dialer features deployed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
