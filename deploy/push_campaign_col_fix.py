#!/usr/bin/env python3
"""Deploy Campaign column match + break details in Destination."""

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
    "app/Services/Communications/CallMonitoringService.php",
    "resources/css/app.css",
    "resources/js/call-monitoring.js",
    "resources/views/communications/monitoring/partials/row.blade.php",
]

REMOTE = r"""
cd /var/www/apexone
npm run build > /tmp/vite-campaign-col.log 2>&1
echo BUILD:$?
tail -n 10 /tmp/vite-campaign-col.log
php artisan view:clear >/dev/null 2>&1 || true
chown -R www-data:www-data /var/www/apexone/public/build
python3 - <<'PY'
from pathlib import Path
js = next(Path('public/build/assets').glob('call-monitoring-*.js'))
css = next(Path('public/build/assets').glob('app-*.css'))
jt = js.read_text(errors='replace')
ct = css.read_text(errors='replace')
print(js.name, 'Break · 5 min', jt.count('Break · 5 min'))
print(css.name, 'row__campaign', ct.count('row__campaign'))
PY
"""


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    upload_files(ssh, pairs, REMOTE_APP)
    print(sudo_run(ssh, REMOTE, check=False).encode("ascii", "replace").decode("ascii"))
    ssh.close()
    print("Campaign column fixed. Hard refresh Ctrl+Shift+R.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
