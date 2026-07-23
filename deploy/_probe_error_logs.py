"""Pull recent Laravel / nginx errors from production."""
from __future__ import annotations

import os
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
os.environ.setdefault("DEPLOY_PASSWORD", "balitech1")

import deploy._ssh as m

m.HOST = "203.215.161.236"
m.USER = "ateg"
m.PASSWORD = "balitech1"
m.REMOTE_APP = "/var/www/apexone"

from deploy._ssh import connect, sudo_run


def main() -> int:
    ssh = connect()
    try:
        cmds = [
            r"""cd /var/www/apexone && python3 - <<'PY'
from pathlib import Path
p = Path('storage/logs/laravel.log')
print('exists', p.exists(), 'size', p.stat().st_size if p.exists() else 0)
if p.exists():
    lines = p.read_text(errors='replace').splitlines()
    hits = [ln for ln in lines if any(k in ln for k in ('ERROR', 'CRITICAL', 'Exception', 'local.ERROR'))]
    print('hits', len(hits))
    for ln in hits[-50:]:
        print(ln[:350])
PY""",
            r"""cd /var/www/apexone/storage/logs && ls -lt | head -15""",
            r"""cd /var/www/apexone && ls -lt public/build/assets/communications-auto-dial*.js | head -2; grep -c handledHangupUuids public/build/assets/communications-auto-dial*.js || true""",
            r"""cd /var/www/apexone && python3 - <<'PY'
from pathlib import Path
p = Path('storage/logs/laravel.log')
if not p.exists():
    print('NO_LOG')
else:
    keys = ('disposition', 'hangup', 'originate', 'Morpheus', 'recording_status', 'SQLSTATE')
    lines = p.read_text(errors='replace').splitlines()
    hits = [ln for ln in lines if any(k.lower() in ln.lower() for k in keys)]
    for ln in hits[-30:]:
        print(ln[:350])
PY""",
        ]
        for i, cmd in enumerate(cmds, 1):
            print(f"\n===== PROBE {i} =====")
            print(sudo_run(ssh, cmd, check=False) or "(empty)")
    finally:
        ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
