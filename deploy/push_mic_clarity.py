#!/usr/bin/env python3
"""Deploy outbound mic clarity fix (USB destination hearing)."""

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

FILES = ["resources/js/communications-webphone.js"]

REMOTE_CHECK = r"""
cd /var/www/apexone
npm run build > /tmp/vite-mic-fix.log 2>&1
echo BUILD:$?
tail -n 10 /tmp/vite-mic-fix.log
php artisan view:clear >/dev/null 2>&1 || true
php artisan cache:clear >/dev/null 2>&1 || true
chown -R www-data:www-data /var/www/apexone/public/build
python3 -c "from pathlib import Path; files=sorted(Path('public/build/assets').glob('communications-*.js'));
[print(p.name, 'openLiveTalkPath', p.read_text(errors='replace').count('openLiveTalkPath'), 'micSilent', p.read_text(errors='replace').count('Outbound mic silent')) for p in files if 'auto-dial' not in p.name]"
"""


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    upload_files(ssh, pairs, REMOTE_APP)
    out = sudo_run(ssh, REMOTE_CHECK, check=False)
    print(out.encode("ascii", "replace").decode("ascii"))
    ssh.close()
    print("Mic clarity fix deployed. Hard refresh Ctrl+Shift+R.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
