#!/usr/bin/env python3
"""Deploy dialer form field id/name fixes for autofill warnings."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

FILES = [
    "resources/views/communications/partials/call-log-row.blade.php",
    "resources/views/communications/partials/center-dialer-hub.blade.php",
    "resources/views/communications/partials/dialer-extension-field.blade.php",
    "resources/views/communications/partials/dialer-form.blade.php",
    "resources/views/communications/partials/call-summary-modal.blade.php",
    "resources/views/communications/partials/phone-notes-panel.blade.php",
    "resources/views/workflows/partials/module-access-role-dropdown.blade.php",
    "resources/js/communications-dialer.js",
    "resources/js/communications-phone-notes.js",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES]
    ssh = connect()
    upload_files(ssh, pairs, REMOTE_APP)
    print(
        sudo_run(
            ssh,
            f"""
cd {REMOTE_APP} && npm run build > /tmp/vite-form-attrs.log 2>&1
tail -n 16 /tmp/vite-form-attrs.log
echo BUILD:$?
chown -R www-data:www-data {REMOTE_APP}/public/build
php artisan view:clear >/dev/null 2>&1 || true
grep -n "call-log-note-\\|dialer-leads-pool\\|line_search\\|transfer_destination\\|module-access-role" \\
  {REMOTE_APP}/resources/views/communications/partials/call-log-row.blade.php \\
  {REMOTE_APP}/resources/views/communications/partials/center-dialer-hub.blade.php \\
  {REMOTE_APP}/resources/views/communications/partials/dialer-extension-field.blade.php \\
  {REMOTE_APP}/resources/views/workflows/partials/module-access-role-dropdown.blade.php | head -20
""",
            check=False,
        )
    )
    ssh.close()
    print("Form id/name fixes deployed. Ctrl+F5 dialer.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
