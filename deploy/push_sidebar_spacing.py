#!/usr/bin/env python3
"""Deploy tighter sidebar spacing."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, sudo_run_batch, upload_files  # noqa: E402

FILES = ["resources/css/app.css"]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES]
    ssh = connect()
    upload_files(ssh, pairs, app_root=REMOTE_APP)
    sudo_run_batch(ssh, [
        f"cd {REMOTE_APP} && touch resources/css/app.css && npm run build > /tmp/apex_sidebar_build.log 2>&1",
        f"chown -R www-data:www-data {REMOTE_APP}/public/build",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear",
    ])
    print(sudo_run(ssh, f"grep -o 'gap:0.15rem' {REMOTE_APP}/public/build/assets/app-*.css | head -1 || grep -c '0.15rem' {REMOTE_APP}/public/build/assets/app-*.css | head -1"))
    print("health", sudo_run(ssh, "curl -fsS -o /dev/null -w '%{http_code}' https://crm.apexonepayments.com/up"))
    ssh.close()
    print("Sidebar spacing tightened.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
