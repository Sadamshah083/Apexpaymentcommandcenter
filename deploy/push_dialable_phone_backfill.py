#!/usr/bin/env python3
"""Deploy dialable-phone persist + backfill so assigned leads show in agent dialer."""

from __future__ import annotations

import base64
import shlex
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, restart_queue_workers, sudo_run, upload_files

FILES = [
    "app/Support/LeadDialablePhone.php",
    "app/Console/Commands/BackfillLeadDialablePhones.php",
    "app/Services/Pipeline/SetterDistributionService.php",
    "app/Services/Workflow/WorkflowExtractor.php",
]

VERIFY = r"""<?php
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$ws = App\Models\Workspace::where('name', 'ApexPayments')->first();
$svc = app(App\Services\Communications\DialerImportedLeadsService::class);

foreach ([14] as $uid) {
    $u = App\Models\User::find($uid);
    $assigned = App\Models\WorkflowLead::query()->where('assigned_user_id', $uid)->count();
    $dialable = $svc->paginate($ws, ['pool' => 'assigned', 'assigned_user_id' => $uid], 0, 5);
    echo ($u->name ?? $uid) . ": assigned_total={$assigned} dialer_total={$dialable['total']} sample=" . ($dialable['leads'][0]['phone'] ?? 'none') . "\n";
}

$missing = App\Models\WorkflowLead::query()
    ->whereNotNull('assigned_user_id')
    ->where(function ($q) {
        $q->whereNull('normalized_phone')->orWhere('normalized_phone', '')
          ->orWhere('normalized_phone', 'not like', '%__________%');
    })
    ->where(function ($q) {
        $q->whereNull('direct_phone')->orWhere('direct_phone', '')
          ->orWhere('direct_phone', 'like', '%Not Publicly%');
    })
    ->count();
echo "assigned_still_missing_phone_cols≈check dialer totals above\n";
"""


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    print(f"Uploading {len(pairs)} files...")
    upload_files(ssh, pairs, app_root=REMOTE_APP)

    print("Clearing caches + running backfill...")
    print(
        sudo_run(
            ssh,
            f"cd {REMOTE_APP} && "
            "sudo -u www-data php artisan optimize:clear && "
            "sudo -u www-data php artisan leads:backfill-dialable-phones --assigned-only --limit=20000",
        )
    )

    remote = f"{REMOTE_APP}/storage/app/_verify_dialable_phones.php"
    b64 = base64.b64encode(VERIFY.encode()).decode()
    sudo_run(ssh, f"printf %s {shlex.quote(b64)} | base64 -d > {remote} && chown www-data:www-data {remote}")
    print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php storage/app/_verify_dialable_phones.php"))
    sudo_run(ssh, f"rm -f {remote}", check=False)

    try:
        restart_queue_workers(ssh)
    except RuntimeError as exc:
        print(f"Warning: queue restart skipped ({exc})")

    ssh.close()
    print("Dialable phone backfill deployed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
