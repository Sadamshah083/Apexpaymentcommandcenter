#!/usr/bin/env python3
"""Deploy dialer On badge + Call Monitoring flicker fixes."""

from __future__ import annotations

import os
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

os.environ.setdefault("DEPLOY_PASSWORD", "SadamShah123")
import deploy._ssh as ssh_mod

ssh_mod.PASSWORD = os.environ.get("DEPLOY_PASSWORD", "") or ssh_mod.PASSWORD
from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

FILES = [
    "app/Services/Communications/CommunicationsInboxService.php",
    "resources/views/communications/partials/global-line-picker.blade.php",
    "resources/views/communications/inbox/partials/toolbar.blade.php",
    "resources/js/communications-webphone.js",
    "resources/js/call-monitoring.js",
]

REMOTE = r"""
cd /var/www/apexone
npm run build > /tmp/vite-on-flicker.log 2>&1
echo BUILD:$?
tail -n 14 /tmp/vite-on-flicker.log
php artisan view:clear >/dev/null 2>&1 || true
chown -R www-data:www-data /var/www/apexone/public/build
python3 - <<'PY'
from pathlib import Path
assets = Path('public/build/assets')
for pat, needles in [
    ('communications-*.js', ['syncCommLiveBadge', 'data-comm-live-badge']),
    ('call-monitoring-*.js', ['breakEndsAt', 'is-break', 'Ends in', 'applyRowColorClass']),
]:
    # communications bundle is large; prefer dedicated chunks when present
    paths = sorted(assets.glob(pat))
    if pat.startswith('communications-') and not pat.startswith('communications-auto'):
        paths = [p for p in paths if 'auto-dial' not in p.name and 'phone-notes' not in p.name]
    for p in paths[:3]:
        t = p.read_text(errors='replace')
        print(p.name)
        for n in needles:
            print(' ', n, t.count(n))
PY
"""


def main() -> int:
    missing = [rel for rel in FILES if not (ROOT / rel).is_file()]
    if missing:
        print("Missing:", missing)
        return 1
    pairs = [(ROOT / rel, rel) for rel in FILES]
    ssh = connect()
    upload_files(ssh, pairs, REMOTE_APP)
    print(sudo_run(ssh, REMOTE, check=False).encode("ascii", "replace").decode("ascii"))
    ssh.close()
    print("Deployed. Hard refresh Ctrl+Shift+R.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
