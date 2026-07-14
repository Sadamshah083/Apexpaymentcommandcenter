#!/usr/bin/env python3
from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files


def main() -> int:
    ssh = connect()
    upload_files(ssh, [(ROOT / "resources/js/call-monitoring.js", "resources/js/call-monitoring.js")], REMOTE_APP)
    print(
        sudo_run(
            ssh,
            f"""
cd {REMOTE_APP} && npm run build > /tmp/vite-build.log 2>&1
tail -n 10 /tmp/vite-build.log
echo BUILD:$?
chown -R www-data:www-data {REMOTE_APP}/public/build
python3 - <<'PY'
from pathlib import Path
built = sorted(Path('{REMOTE_APP}/public/build/assets').glob('call-monitoring*.js'), key=lambda p: p.stat().st_mtime, reverse=True)[0]
t = built.read_text()
print('built', built.name)
print('poll_10s', t.count('1e4'))
print('src_has_10000', '10000' in Path('{REMOTE_APP}/resources/js/call-monitoring.js').read_text())
PY
""",
            check=False,
        )
    )
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
