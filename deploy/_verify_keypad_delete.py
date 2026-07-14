#!/usr/bin/env python3
from __future__ import annotations

import shlex
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import PASSWORD, REMOTE_APP, connect


def main() -> int:
    ssh = connect()
    cmd = f"""
cd {REMOTE_APP}
echo '--- blade ---'
grep -n 'keypad-delete\\|keypad__display\\|Clear' resources/views/communications/partials/dialer-form.blade.php | head -20
echo '--- js build ---'
grep -l 'deleteActiveKeypadDigit' public/build/assets/*.js | head -3
grep -o 'deleteActiveKeypadDigit' public/build/assets/*.js | head -3
echo '--- css ---'
grep -l 'active-keypad__delete' public/build/assets/*.css | head -3
sudo -u www-data php artisan view:clear
"""
    full = f"echo {shlex.quote(PASSWORD)} | sudo -S bash -lc {shlex.quote(cmd)}"
    _, out, err = ssh.exec_command(full)
    print(out.read().decode(errors="replace"))
    e = err.read().decode(errors="replace")
    if e.strip():
        print(e[-1000:])
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
