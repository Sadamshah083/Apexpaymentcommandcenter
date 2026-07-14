#!/usr/bin/env python3
"""Deploy agent imported-leads visibility + hide lead pool for agents."""

from __future__ import annotations

import base64
import shlex
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, restart_queue_workers, sudo_run, upload_files

FILES = [
    "app/Services/Communications/DialerImportedLeadsService.php",
    "app/Http/Controllers/CommunicationsHubController.php",
    "resources/views/communications/partials/center-dialer-hub.blade.php",
    "resources/js/communications-auto-dial.js",
]

VERIFY = r"""<?php
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$ws = App\Models\Workspace::where('name', 'ApexPayments')->first();
$svc = app(App\Services\Communications\DialerImportedLeadsService::class);
foreach ([14, 15, 21] as $uid) {
    $u = App\Models\User::find($uid);
    $all = $svc->paginate($ws, ['pool' => 'assigned', 'assigned_user_id' => $uid], 0, 5);
    $fronter = $svc->paginate($ws, ['pool' => 'assigned', 'assigned_user_id' => $uid, 'campaign_id' => 5], 0, 5);
    echo "{$u->name}: total={$all['total']} page=".count($all['leads'])." fronter={$fronter['total']} sample_phone=".($all['leads'][0]['phone'] ?? 'none')."\n";
}
"""


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    print(f"Uploading {len(pairs)} files...")
    upload_files(ssh, pairs, app_root=REMOTE_APP)

    cmd = (
        f"cd {REMOTE_APP} && "
        "rm -rf node_modules/.vite node_modules/.vite-temp && "
        "npm run build > /tmp/apex_vite_build.log 2>&1; "
        "echo EXIT:$? >> /tmp/apex_vite_build.log; "
        f"chown -R www-data:www-data {REMOTE_APP}/public/build; "
        "tail -n 15 /tmp/apex_vite_build.log"
    )
    print(sudo_run(ssh, cmd))
    print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear", check=False))

    remote = f"{REMOTE_APP}/storage/app/_verify_agent_leads.php"
    b64 = base64.b64encode(VERIFY.encode()).decode()
    sudo_run(ssh, f"printf %s {shlex.quote(b64)} | base64 -d > {remote} && chown www-data:www-data {remote}")
    print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php storage/app/_verify_agent_leads.php"))
    sudo_run(ssh, f"rm -f {remote}", check=False)

    try:
        restart_queue_workers(ssh)
    except RuntimeError as exc:
        print(f"Warning: queue restart skipped ({exc})")

    ssh.close()
    print("Agent dialer lead visibility fix deployed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
