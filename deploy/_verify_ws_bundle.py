#!/usr/bin/env python3
import os
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
os.environ["DEPLOY_PASSWORD"] = os.environ.get("DEPLOY_PASSWORD") or "SadamShah123"
import deploy._ssh as m

m.PASSWORD = os.environ["DEPLOY_PASSWORD"]
ssh = m.connect()
out = m.sudo_run(
    ssh,
    r"""
cd /var/www/apexone
python3 - <<'PY'
from pathlib import Path
p = Path('public/build/assets/communications-DMXfSVPn.js')
text = p.read_text(errors='replace')
needles = [
    'will use SSE if it closes',
    'page-wide socket',
    'callEventsSubscribeGeneration',
]
for n in needles:
    print(f'{n}: {text.count(n)}')
print('bundle_bytes', p.stat().st_size)
PY
""",
    check=False,
)
print(out)
ssh.close()
