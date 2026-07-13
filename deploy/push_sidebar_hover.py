#!/usr/bin/env python3
"""Deploy sidebar hover URL preview suppression."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, sudo_run_batch, upload_files  # noqa: E402

FILES = [
    "resources/js/sidebar.js",
    "resources/js/app.js",
    "resources/views/components/sidebar/link.blade.php",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    upload_files(ssh, pairs, app_root=REMOTE_APP)
    sudo_run_batch(ssh, [
        f"cd {REMOTE_APP} && touch resources/js/sidebar.js resources/js/app.js && npm run build > /tmp/apex_sidebar_hover.log 2>&1",
        f"chown -R www-data:www-data {REMOTE_APP}/public/build",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear",
    ])
    print(sudo_run(ssh, f"grep -c statusHref {REMOTE_APP}/resources/js/sidebar.js"))
    print("health", sudo_run(ssh, "curl -fsS -o /dev/null -w '%{http_code}' https://crm.apexonepayments.com/up"))
    ssh.close()
    print("Sidebar URL hover preview suppressed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
