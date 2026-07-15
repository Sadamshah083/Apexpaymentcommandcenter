#!/usr/bin/env python3
"""Hotfix Call Notes 500 (missing CommunicationCallLog import)."""

from __future__ import annotations

import base64
import shlex
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, restart_queue_workers, sudo_run, upload_files

FILES = ["app/Services/Communications/CallNotesHistoryService.php"]

VERIFY = r"""<?php
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try {
    $ws = App\Models\Workspace::where('name', 'ApexPayments')->first();
    $svc = app(App\Services\Communications\CallNotesHistoryService::class);
    $notes = $svc->notesForAgent($ws, 14, 5);
    echo "ok total=".$notes->total()." page=".count($notes->items())."\n";
} catch (Throwable $e) {
    echo "FAIL ".$e->getMessage()." @ ".$e->getFile().":".$e->getLine()."\n";
    exit(1);
}
"""


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES]
    ssh = connect()
    upload_files(ssh, pairs, app_root=REMOTE_APP)
    sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php artisan optimize:clear", check=False)

    remote = f"{REMOTE_APP}/storage/app/_verify_notes_fix.php"
    b64 = base64.b64encode(VERIFY.encode()).decode()
    sudo_run(ssh, f"printf %s {shlex.quote(b64)} | base64 -d > {remote} && chown www-data:www-data {remote}")
    print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php storage/app/_verify_notes_fix.php"))
    sudo_run(ssh, f"rm -f {remote}", check=False)

    try:
        restart_queue_workers(ssh)
    except RuntimeError as exc:
        print(f"Warning: queue restart skipped ({exc})")

    ssh.close()
    print("Call Notes 500 fix deployed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
