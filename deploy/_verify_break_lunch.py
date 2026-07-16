#!/usr/bin/env python3
from __future__ import annotations

import os
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
os.environ.setdefault("DEPLOY_PASSWORD", "SadamShah123")
import deploy._ssh as ssh_mod

ssh_mod.PASSWORD = os.environ.get("DEPLOY_PASSWORD", "") or ssh_mod.PASSWORD
from deploy._ssh import connect, sudo_run

REMOTE = r"""
cd /var/www/apexone
python3 - <<'PY'
from pathlib import Path
assets = Path('public/build/assets')
for pat in ('call-monitoring-*.js', 'communications-auto-dial-*.js'):
    p = next(assets.glob(pat), None)
    print('FILE', p.name if p else pat)
    if not p:
        continue
    t = p.read_text(errors='replace')
    for s in ('is-break', 'is-lunch', 'BREAK', 'LUNCH', 'initAgentBreakControls', 'Break In', 'remaining_seconds', 'data-stat'):
        print(' ', s, t.count(s))
PY
php artisan tinker --execute="echo Schema::hasTable('agent_activity_sessions') ? 'table:yes' : 'table:no';"
"""

ssh = connect()
print(sudo_run(ssh, REMOTE, check=False))
ssh.close()
