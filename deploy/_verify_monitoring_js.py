#!/usr/bin/env python3
from __future__ import annotations

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
src = Path('{REMOTE_APP}/resources/js/call-monitoring.js')
built = sorted(Path('{REMOTE_APP}/public/build/assets').glob('call-monitoring*.js'), key=lambda p: p.stat().st_mtime, reverse=True)[0]
print('SRC exists', src.exists(), 'bytes', src.stat().st_size if src.exists() else 0)
print('BUILT', built.name, 'bytes', built.stat().st_size)
st = src.read_text(errors='replace')
bt = built.read_text(errors='replace')
for label, t in [('src', st), ('built', bt)]:
    print('---', label)
    for s in ['navOnly', '30000', '12000', 'EventSource', 'stream unavailable', 'Sidebar chips']:
        print(f'  {{s}}: {{t.count(s)}}')
# show src snippet around navOnly
i = st.find('navOnly')
print('src snip:', repr(st[i:i+80]) if i>=0 else 'missing')
print('manifest entry:')
man = Path('{REMOTE_APP}/public/build/manifest.json')
import json
m = json.loads(man.read_text())
for k,v in m.items():
    if 'call-monitoring' in k or (isinstance(v, dict) and 'call-monitoring' in str(v.get('file',''))):
        print(k, '->', v)
PY
""",
        check=False,
    )
    print(out)
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
