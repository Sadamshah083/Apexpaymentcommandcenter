#!/usr/bin/env python3
"""Deploy Call Notes agent picker role scoping."""

from __future__ import annotations

import base64
import shlex
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, restart_queue_workers, sudo_run, upload_files

FILES = [
    "app/Services/Communications/CallNotesHistoryService.php",
    "app/Http/Controllers/CallNotesController.php",
]

VERIFY = r"""<?php
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$ws = App\Models\Workspace::where('name', 'ApexPayments')->first();
$svc = app(App\Services\Communications\CallNotesHistoryService::class);
$admin = App\Models\User::where('name', 'like', '%admin%')->orWhere('email', 'like', '%admin%')->first();
$admin = App\Models\User::query()->whereHas('workspaces', fn ($q) => $q->where('workspaces.id', $ws->id)->whereIn('workspace_user.role', ['admin','super_admin']))->first()
    ?: App\Models\User::find(1);

$agents = $svc->dialerAgents($ws, $admin, 'admin');
echo "admin_picker_count=".$agents->count()."\n";
foreach ($agents as $a) {
    $role = strtolower($a['role']);
    if (str_contains($role, 'admin')) {
        echo "FAIL still has admin: {$a['name']} — {$a['role']}\n";
    }
}
$sample = $agents->take(8)->map(fn ($a) => $a['name'].' — '.$a['role'])->implode("\n");
echo "sample:\n{$sample}\n";

$tl = $ws->users()->wherePivot('role', 'appointment_setter_team_lead')->wherePivot('status','active')->first();
if ($tl) {
    $scoped = $svc->dialerAgents($ws, $tl, 'team_lead');
    echo "team_lead={$tl->name} scoped_count=".$scoped->count()."\n";
    echo "scoped:\n".$scoped->map(fn ($a) => $a['name'].' — '.$a['role'])->implode("\n")."\n";
} else {
    echo "no_team_lead_found\n";
}
"""


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    print(f"Uploading {len(pairs)} files...")
    upload_files(ssh, pairs, app_root=REMOTE_APP)
    print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear && sudo -u www-data php artisan optimize:clear", check=False))

    remote = f"{REMOTE_APP}/storage/app/_verify_call_notes_agents.php"
    b64 = base64.b64encode(VERIFY.encode()).decode()
    sudo_run(ssh, f"printf %s {shlex.quote(b64)} | base64 -d > {remote} && chown www-data:www-data {remote}")
    print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php storage/app/_verify_call_notes_agents.php"))
    sudo_run(ssh, f"rm -f {remote}", check=False)

    try:
        restart_queue_workers(ssh)
    except RuntimeError as exc:
        print(f"Warning: queue restart skipped ({exc})")

    ssh.close()
    print("Call Notes agent picker fix deployed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
