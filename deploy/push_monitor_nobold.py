#!/usr/bin/env python3
"""Deploy Call Monitoring non-bold text weights."""

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

FILES = ["resources/css/app.css"]

REMOTE = r"""
cd /var/www/apexone
npm run build > /tmp/vite-monitor-weight.log 2>&1
echo BUILD:$?
tail -n 8 /tmp/vite-monitor-weight.log
chown -R www-data:www-data /var/www/apexone/public/build
python3 - <<'PY'
from pathlib import Path
css = next(Path('public/build/assets').glob('app-*.css'))
t = css.read_text(errors='replace')
print(css.name)
# spot-check nearby monitoring weight rules
idx = t.find('call-monitoring-row__timer')
print('timer snippet', t[idx:idx+120].replace('\n',' ') if idx>=0 else 'missing')
idx = t.find('call-monitoring-row__name')
print('name snippet', t[idx:idx+80].replace('\n',' ') if idx>=0 else 'missing')
PY
"""


def main() -> int:
    ssh = connect()
    upload_files(ssh, [(ROOT / f, f) for f in FILES], REMOTE_APP)
    print(sudo_run(ssh, REMOTE, check=False).encode("ascii", "replace").decode("ascii"))
    ssh.close()
    print("Non-bold monitoring text deployed. Hard refresh Ctrl+Shift+R.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
