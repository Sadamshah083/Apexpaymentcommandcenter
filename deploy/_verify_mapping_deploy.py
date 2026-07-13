#!/usr/bin/env python3
from __future__ import annotations

import shlex
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import PASSWORD, REMOTE_APP, connect


def run(ssh, cmd: str) -> str:
    full = f"echo {shlex.quote(PASSWORD)} | sudo -S bash -lc {shlex.quote(cmd)}"
    _, stdout, stderr = ssh.exec_command(full, timeout=30)
    out = stdout.read().decode(errors="replace", encoding="utf-8")
    err = stderr.read().decode(errors="replace", encoding="utf-8")
    text = out + "\n" + err
    lines = [ln for ln in text.splitlines() if "[sudo]" not in ln and "\u25cf" not in ln]
    return "\n".join(lines).strip()


def main() -> int:
    ssh = connect()
    print(
        run(
            ssh,
            f"""
cd {REMOTE_APP}
echo WORKERS:
pgrep -c -f 'queue:work' || true
pgrep -af 'queue:work' | head -10
echo ---
echo ASSETS:
ls -la public/build/assets/*pretty* 2>/dev/null || echo 'no pretty chunk name'
grep -l pretty-select public/build/manifest.json 2>/dev/null && echo manifest_ok || echo no_manifest
grep -o 'pretty-select' public/build/manifest.json | head -3 || true
# check built css has pretty-select
grep -l 'pretty-select__trigger' public/build/assets/*.css 2>/dev/null | head -3 || echo 'css missing pretty-select'
echo ---
echo JOBS:
sudo -u www-data php artisan tinker --execute='echo App\\Models\\WorkflowLead::where(\"status\",\"pending\")->orWhere(\"status\",\"processing\")->count();' 2>/dev/null | tail -5 || true
mysql -N -e "SELECT COUNT(*) FROM jobs;" apexone 2>/dev/null || sudo -u www-data php -r 'require "vendor/autoload.php"; \$a=require "bootstrap/app.php"; \$a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); echo "jobs=".DB::table("jobs")->count().PHP_EOL;'
""",
        )
    )
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
