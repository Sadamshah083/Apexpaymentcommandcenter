#!/usr/bin/env python3
from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run


def main() -> int:
    ssh = connect()
    cmd = (
        f"cd {REMOTE_APP} && "
        "rm -rf node_modules/.vite node_modules/.vite-temp && "
        "npm run build > /tmp/apex_vite_build.log 2>&1; "
        "echo EXIT:$? >> /tmp/apex_vite_build.log; "
        f"chown -R www-data:www-data {REMOTE_APP}/public/build; "
        "tail -n 40 /tmp/apex_vite_build.log"
    )
    print(sudo_run(ssh, cmd))

    check = (
        f"cd {REMOTE_APP} && python3 - <<'PY'\n"
        "from pathlib import Path\n"
        "root = Path('public/build/assets')\n"
        "print('assets', len(list(root.glob('*.js'))) if root.exists() else 0)\n"
        "needle = bytes([0xCE, 0x93, 0xC3, 0x87, 0xC3, 0xB6])\n"
        "bad = 0\n"
        "for f in (root.glob('*.js') if root.exists() else []):\n"
        "    if needle in f.read_bytes():\n"
        "        bad += 1\n"
        "        print('BAD', f.name)\n"
        "print('bad_files', bad)\n"
        "PY"
    )
    print(sudo_run(ssh, check))
    sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear", check=False)
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
