#!/usr/bin/env python3
"""Deploy presence API single-call / dedupe fix."""

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

FILES = ["resources/js/communications-auto-dial.js"]

REMOTE = r"""
cd /var/www/apexone
npm run build > /tmp/vite-presence-dedupe.log 2>&1
echo BUILD:$?
tail -n 8 /tmp/vite-presence-dedupe.log
php artisan view:clear >/dev/null 2>&1 || true
chown -R www-data:www-data /var/www/apexone/public/build
python3 -c "from pathlib import Path; p=next(Path('public/build/assets').glob('communications-auto-dial-*.js')); t=p.read_text(errors='replace'); print(p.name); print('presenceQueuedExtra', t.count('presenceQueuedExtra')); print('lastPresenceSignature', t.count('lastPresenceSignature')); print('announcePresence', t.count('announcePresence'))"
"""


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    upload_files(ssh, pairs, REMOTE_APP)
    print(sudo_run(ssh, REMOTE, check=False).encode("ascii", "replace").decode("ascii"))
    ssh.close()
    print("Presence dedupe deployed. Hard refresh Ctrl+Shift+R.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
