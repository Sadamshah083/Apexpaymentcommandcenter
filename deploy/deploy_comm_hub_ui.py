#!/usr/bin/env python3
"""Deploy Communications Hub enterprise UI (atomic design) to production."""
from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, sudo_run_batch, upload_files

FILES = [
    "app/Http/Controllers/MorpheusHubController.php",
    "app/Services/Integrations/ZoomApiService.php",
    "config/integrations.php",
    "resources/css/app.css",
    "resources/css/comm-hub-atomic.css",
    "resources/css/comm-hub-ui-polish.css",
    "resources/css/comm-hub-ghl-theme.css",
    "resources/css/communications-inbox.css",
    "resources/js/app.js",
    "resources/js/toast.js",
    "resources/js/comm-hub-workflow.js",
    "resources/js/communications-dialer.js",
    "resources/js/communications-phone-notes.js",
    "resources/js/communications-webphone.js",
    "resources/views/communications/inbox/index.blade.php",
    "resources/views/communications/inbox/partials/empty.blade.php",
    "resources/views/communications/inbox/partials/nav.blade.php",
    "resources/views/communications/inbox/partials/nav-item.blade.php",
    "resources/views/communications/inbox/partials/nav-icon.blade.php",
    "resources/views/communications/inbox/partials/list.blade.php",
    "resources/views/communications/inbox/partials/right-dial-rail.blade.php",
    "resources/views/communications/inbox/partials/main.blade.php",
    "app/Http/Controllers/CommunicationsHubController.php",
    "app/Services/Communications/CommunicationsInboxService.php",
    "app/Services/Communications/CommunicationsCallHistoryService.php",
    "app/Services/Communications/CommunicationsCallRecordingService.php",
    "app/Services/Communications/CommunicationsPhoneNotesService.php",
    "app/Jobs/SyncCallRecordingJob.php",
    "app/Models/CommunicationCallLog.php",
    "app/Models/CommunicationPhoneNote.php",
    "database/migrations/2026_07_10_000001_create_communication_phone_notes_table.php",
    "database/migrations/2026_07_10_100000_add_recording_fields_to_communication_call_logs_table.php",
    "routes/web.php",
    "app/Services/Communications/CommunicationsDataService.php",
    "resources/views/communications/inbox/partials/toolbar.blade.php",
    "resources/views/communications/inbox/partials/channels-menu.blade.php",
    "resources/views/communications/partials/webphone-connect-strip.blade.php",
    "app/Services/Communications/CommunicationsCallHistoryService.php",
    "resources/views/communications/partials/global-line-picker.blade.php",
    "resources/views/communications/partials/call-log-row.blade.php",
    "resources/views/communications/partials/center-dialer-hub.blade.php",
    "resources/views/communications/partials/phone-notes-panel.blade.php",
    "resources/views/communications/partials/dialer-form.blade.php",
    "resources/views/communications/partials/dialer-extension-field.blade.php",
    "resources/views/communications/partials/webphone-floating-popup.blade.php",
    "resources/views/communications/partials/webphone-panel.blade.php",
    "resources/views/components/communications/atoms/badge.blade.php",
    "resources/views/components/communications/atoms/button.blade.php",
    "resources/views/components/communications/atoms/label.blade.php",
    "resources/views/components/communications/molecules/alert.blade.php",
    "resources/views/components/communications/molecules/section-header.blade.php",
    "resources/views/components/communications/molecules/stat-grid.blade.php",
    "resources/views/components/communications/molecules/workflow-stepper.blade.php",
    "resources/views/components/communications/organisms/empty-state.blade.php",
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
        f"cd {REMOTE_APP} && npm ci --prefer-offline --no-audit 2>/dev/null || npm install --no-audit",
        f"cd {REMOTE_APP} && npm run build",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan migrate --force --no-interaction",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan route:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan config:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan config:cache",
        f"chown -R www-data:www-data {REMOTE_APP}/public/build",
        "systemctl reload php8.3-fpm 2>/dev/null || true",
        "systemctl reload nginx 2>/dev/null || true",
    ])

    print("Health:", sudo_run(ssh, "curl -fsS https://crm.apexonepayments.com/up | head -5", check=False))
    ssh.close()
    print("Deploy complete.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
