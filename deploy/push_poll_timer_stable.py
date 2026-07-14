#!/usr/bin/env python3
"""Deploy dialer poll/session fixes + stable Call Monitoring timer columns."""
from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

FILES = [
    "app/Http/Controllers/MorpheusHubController.php",
    "resources/js/communications-webphone.js",
    "resources/js/call-monitoring.js",
    "resources/css/app.css",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    print(f"Uploading {len(pairs)} files...")
    upload_files(ssh, pairs, app_root=REMOTE_APP)
    print(
        sudo_run(
            ssh,
            f"""
cd {REMOTE_APP} && npm run build > /tmp/vite-build.log 2>&1
tail -n 20 /tmp/vite-build.log
echo BUILD:$?
chown -R www-data:www-data {REMOTE_APP}/public/build
cd {REMOTE_APP} && sudo -u www-data php artisan view:clear
cd {REMOTE_APP} && sudo -u www-data php artisan cache:clear
python3 - <<'PY'
from pathlib import Path
app = Path('{REMOTE_APP}')
ctrl = (app/'app/Http/Controllers/MorpheusHubController.php').read_text()
print('callStatus_unlock', 'Unlock immediately so destination-connected' in ctrl)
print('mark_unlock', ctrl.count('ReleaseSessionLock::now($request)'))
wp = (app/'resources/js/communications-webphone.js').read_text()
print('poll_timeout_12000', '12000' in wp)
print('delay_4000_incall', "delayMs = hasEvents ? 4000" in wp)
cm = (app/'resources/js/call-monitoring.js').read_text()
print('padStart_timer', "padStart(2, '0')" in cm and 'patchRow' in cm)
built = sorted((app/'public/build/assets').glob('call-monitoring*.js'), key=lambda p: p.stat().st_mtime, reverse=True)[0]
print('built_cm', built.name)
comm = sorted((app/'public/build/assets').glob('communications-*.js'), key=lambda p: p.stat().st_mtime, reverse=True)
print('built_comm', [p.name for p in comm[:3]])
PY
""",
            check=False,
        )
    )
    ssh.close()
    print("Deployed. Ctrl+F5 dialer + Call Monitoring.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
