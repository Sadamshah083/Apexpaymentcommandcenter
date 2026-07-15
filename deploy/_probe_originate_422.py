#!/usr/bin/env python3
from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run

ssh = connect()
cmd = r"""
python3 <<'PY'
from pathlib import Path
log = Path('/var/www/apexone/storage/logs/laravel.log')
if log.exists():
    lines = log.read_text(errors='replace').splitlines()[-600:]
    keys = ('originate', 'campaign', 'destination', 'busy', 'offline', 'webphone', 'user_busy', 'extension')
    matched = []
    for line in lines:
        low = line.lower()
        if any(k in low for k in keys):
            matched.append(line[-480:])
    print('\n'.join(matched[-40:]) if matched else 'NO_MATCHES')
else:
    print('NO_LARAVEL_LOG')
PY
echo ---NGINX---
grep -E 'calls/originate' /var/log/nginx/access.log 2>/dev/null | tail -15 || true
echo ---ENV---
grep -E 'MORPHEUS_DEFAULT_CAMPAIGN_ID|MORPHEUS_DIAL_METHOD|MORPHEUS_ORIGINATE_METHOD' /var/www/apexone/.env || true
"""
print(sudo_run(ssh, cmd, check=False))
ssh.close()
