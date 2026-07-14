#!/usr/bin/env python3
from __future__ import annotations

import hashlib
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

FILES = [
    "resources/js/workspace-sync.js",
    "resources/js/portal-dashboard.js",
    "resources/js/app.js",
]


def md5(path: Path) -> str:
    return hashlib.md5(path.read_bytes()).hexdigest()


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    print("Local MD5:")
    for local, rel in pairs:
        print(f"  {rel}: {md5(local)}")

    upload_files(ssh, pairs, app_root=REMOTE_APP)

    print("Remote MD5 + emdash byte check:")
    print(
        sudo_run(
            ssh,
            f"cd {REMOTE_APP} && "
            "md5sum resources/js/workspace-sync.js resources/js/portal-dashboard.js && "
            "python3 - <<'PY'\n"
            "from pathlib import Path\n"
            "p=Path('resources/js/workspace-sync.js').read_bytes()\n"
            "print('has_proper_emdash', b'\\xe2\\x80\\x94' in p)\n"
            "print('has_mojibake_utf8', 'ΓÇö'.encode() in p)\n"
            "PY",
        )
    )

    print("Force clean Vite rebuild...")
    print(
        sudo_run(
            ssh,
            f"cd {REMOTE_APP} && "
            "sudo rm -rf public/build node_modules/.vite && "
            "sudo -u www-data npm run build 2>&1 | tail -30",
            check=False,
        )
    )

    print("Build mojibake check:")
    print(
        sudo_run(
            ssh,
            f"cd {REMOTE_APP} && "
            "python3 - <<'PY'\n"
            "from pathlib import Path\n"
            "root=Path('public/build/assets')\n"
            "bad=0\n"
            "needle='ΓÇö'.encode()\n"
            "for f in root.glob('*.js'):\n"
            "    data=f.read_bytes()\n"
            "    if needle in data:\n"
            "        bad+=1\n"
            "        print('BAD', f.name, data.count(needle))\n"
            "print('bad_files', bad)\n"
            "print('assets', len(list(root.glob('*.js'))))\n"
            "PY",
            check=False,
        )
    )
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
