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
    _, stdout, stderr = ssh.exec_command(full)
    out = stdout.read().decode(errors="replace")
    err = stderr.read().decode(errors="replace")
    return (out + ("\n" + err if err.strip() else "")).strip()


def main() -> int:
    ssh = connect()
    print("=== config ===")
    print(
        run(
            ssh,
            f"""cd {REMOTE_APP}
php -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); echo "queries=".config("workflow_enrichment.web_search_queries")." follow=".var_export(config("workflow_enrichment.follow_up_enabled"), true)." workers=".config("queue.workers").PHP_EOL;'
""",
        )
    )
    print("=== queue pool source ===")
    print(
        run(
            ssh,
            f"""cd {REMOTE_APP}
grep -n -E 'workers|QUEUE_WORKERS|spawn|pool' app/Console/Commands/*Pool* 2>/dev/null | head -40
ls app/Console/Commands/ | grep -i pool || true
find app -name '*Pool*' 2>/dev/null
""",
        )
    )
    print("=== restart pool with 6 workers ===")
    print(
        run(
            ssh,
            f"""cd {REMOTE_APP}
# kill existing pool/workers
pkill -f 'artisan queue:(pool|work)' || true
sleep 2
# start pool as www-data in background
nohup sudo -u www-data php artisan queue:pool --tries=3 --timeout=0 >/var/www/apexone/storage/logs/queue-pool.log 2>&1 &
sleep 3
pgrep -af 'queue:(work|pool)' || true
""",
        )
    )
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
