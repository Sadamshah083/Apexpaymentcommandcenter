#!/usr/bin/env python3
"""Deploy Call Monitoring table header column alignment fix."""

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
    "resources/css/app.css",
    "resources/views/communications/monitoring/partials/wallboard.blade.php",
    "resources/views/communications/monitoring/partials/table-section.blade.php",
    "resources/views/communications/monitoring/partials/row.blade.php",
    "resources/js/call-monitoring.js",
]

REMOTE = r"""
cd /var/www/apexone
npm run build > /tmp/vite-monitor-header.log 2>&1
echo BUILD:$?
tail -n 12 /tmp/vite-monitor-header.log
php artisan view:clear >/dev/null 2>&1 || true
chown -R www-data:www-data /var/www/apexone/public/build
python3 - <<'PY'
from pathlib import Path
css = next(Path('public/build/assets').glob('app-*.css'), None)
js = next(Path('public/build/assets').glob('call-monitoring-*.js'), None)
print('css', css.name if css else None)
if css:
    t = css.read_text(errors='replace')
    print(' cm-col-user', t.count('cm-col-user'))
    print(' user-inner', t.count('user-inner'))
print('js', js.name if js else None)
if js:
    t = js.read_text(errors='replace')
    print(' user-inner', t.count('user-inner'))
PY
"""


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    upload_files(ssh, pairs, REMOTE_APP)
    print(sudo_run(ssh, REMOTE, check=False).encode("ascii", "replace").decode("ascii"))
    ssh.close()
    print("Header alignment deployed. Hard refresh Ctrl+Shift+R.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
