#!/usr/bin/env python3
from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run


def main() -> int:
    ssh = connect()
    out = sudo_run(
        ssh,
        f"""
python3 - <<'PY'
from pathlib import Path
import re
built = sorted(Path('{REMOTE_APP}/public/build/assets').glob('call-monitoring*.js'), key=lambda p: p.stat().st_mtime, reverse=True)[0]
t = built.read_text(errors='replace')
print('3e4', t.count('3e4'), '1e4', t.count('1e4'), '12e3', t.count('12e3'))
# find setInterval usages
for m in re.finditer(r'setInterval\\((.{{0,80}})\\)', t):
    print('setInterval:', m.group(0)[:120])
# find board && nav patterns
for pat in ['board&&', '!board', 'navLinks', '3e4', '30*']:
    print(pat, t.count(pat))
idx = t.find('EventSource')
print(t[idx-200:idx+250])
PY
""",
        check=False,
    )
    print(out)
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
