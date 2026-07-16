#!/usr/bin/env python3
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


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    upload_files(ssh, pairs, REMOTE_APP)
    out = sudo_run(
        ssh,
        f"""
cd {REMOTE_APP}
grep -c sharedCallEventsUuid resources/js/communications-webphone.js || true
npm run build > /tmp/vite-ws-once2.log 2>&1
echo BUILD:$?
tail -n 8 /tmp/vite-ws-once2.log
php artisan view:clear >/dev/null 2>&1 || true
php artisan cache:clear >/dev/null 2>&1 || true
chown -R www-data:www-data {REMOTE_APP}/public/build
ls -1 {REMOTE_APP}/public/build/assets/communications-*.js
grep -c sharedCallEventsUuid {REMOTE_APP}/public/build/assets/communications-*.js || true
""",
        check=False,
    )
    print(out.encode("ascii", "replace").decode("ascii"))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
