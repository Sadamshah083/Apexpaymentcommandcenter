#!/usr/bin/env python3
from __future__ import annotations

import base64
import shlex
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run

PHP = r"""<?php
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$ws = App\Models\Workspace::where('name', 'ApexPayments')->first();
$svc = app(App\Services\Communications\DialerImportedLeadsService::class);

foreach ([14,15,16,18,19,21] as $uid) {
    $u = App\Models\User::find($uid);
    $all = $svc->paginate($ws, ['pool' => 'assigned', 'assigned_user_id' => $uid], 0, 5);
    $fronter = $svc->paginate($ws, ['pool' => 'assigned', 'assigned_user_id' => $uid, 'campaign_id' => 5], 0, 5);
    $closer = $svc->paginate($ws, ['pool' => 'assigned', 'assigned_user_id' => $uid, 'campaign_id' => 2], 0, 5);
    echo "{$u->name}: all_total={$all['total']} returned=".count($all['leads'])." fronter={$fronter['total']} closer={$closer['total']}\n";
    if (!empty($all['leads'][0])) {
        echo '  sample='.json_encode($all['leads'][0])."\n";
    }
}

// Why SQL dialable != returned: phone string junk
$junk = App\Models\WorkflowLead::query()
    ->whereNotNull('assigned_user_id')
    ->where(function ($q) {
        $q->where('direct_phone', 'like', '%Not Publicly%')
          ->orWhere('input_phone', 'like', '%Not Publicly%')
          ->orWhere('normalized_phone', 'like', '%Not Publicly%');
    })->count();
echo "assigned_with_not_publicly_phone={$junk}\n";

$real = App\Models\WorkflowLead::query()
    ->whereNotNull('assigned_user_id')
    ->where(function ($q) {
        $q->whereNotNull('normalized_phone')
          ->orWhereRaw("direct_phone REGEXP '^[0-9+(). -]{10,}$'")
          ->orWhereRaw("input_phone REGEXP '^[0-9+(). -]{10,}$'");
    })->count();
echo "assigned_with_realish_phone={$real}\n";
"""


def main() -> int:
    ssh = connect()
    remote = f"{REMOTE_APP}/storage/app/_probe_agent_leads2.php"
    b64 = base64.b64encode(PHP.encode()).decode()
    sudo_run(ssh, f"printf %s {shlex.quote(b64)} | base64 -d > {remote} && chown www-data:www-data {remote}")
    print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php storage/app/_probe_agent_leads2.php"))
    sudo_run(ssh, f"rm -f {remote}", check=False)
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
