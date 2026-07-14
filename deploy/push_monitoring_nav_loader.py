#!/usr/bin/env python3
"""Deploy nav/loader fix: no SSE on Call Monitoring (stops tab spinner + frees FPM)."""
from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, sudo_run_batch, upload_files

FILES = [
    "resources/js/call-monitoring.js",
    "resources/js/app.js",
    "resources/css/app.css",
    "app/Http/Controllers/CallMonitoringController.php",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    print(f"Uploading {len(pairs)} files...")
    upload_files(ssh, pairs, app_root=REMOTE_APP)
    out = sudo_run_batch(
        ssh,
        [
            f"cd {REMOTE_APP} && npm run build > /tmp/vite-build.log 2>&1; tail -n 25 /tmp/vite-build.log; echo BUILD:$?",
            f"chown -R www-data:www-data {REMOTE_APP}/public/build",
            f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear",
            f"cd {REMOTE_APP} && sudo -u www-data php artisan cache:clear",
            # Drop stuck monitoring SSE workers if any are still hanging.
            "pkill -f 'CallMonitoringController' >/dev/null 2>&1 || true",
            f"""python3 - <<'PY'
from pathlib import Path
built = sorted(Path('{REMOTE_APP}/public/build/assets').glob('call-monitoring*.js'), key=lambda p: p.stat().st_mtime, reverse=True)[0]
t = built.read_text(errors='replace')
print('BUILT', built.name)
print('EventSource', t.count('EventSource'))
print('BOARD_POLL or 2e3', '2e3' in t or '2000' in t, t.count('2e3'))
print('no permanent SSE:', t.count('EventSource') == 0)
PY""",
        ],
        check=False,
    )
    print(out)
    ssh.close()
    print("Deployed. Ctrl+F5 Call Monitoring — tab spinner should stop; sidebar nav should feel snappy again.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
