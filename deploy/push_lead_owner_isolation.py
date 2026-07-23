#!/usr/bin/env python3
"""Deploy dialer lead ownership isolation (assigned leads private per agent)."""
from __future__ import annotations

import os
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
os.environ.setdefault("DEPLOY_PASSWORD", "balitech1")

import deploy._ssh as m

m.HOST = "203.215.161.236"
m.USER = "ateg"
m.PASSWORD = "balitech1"
m.REMOTE_APP = "/var/www/apexone"

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

FILES = [
    "app/Services/Communications/DialerImportedLeadsService.php",
    "app/Http/Controllers/CommunicationsHubController.php",
]

VERIFY = r"""<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\Workspace;
use App\Services\Communications\DialerImportedLeadsService;

$ws = Workspace::where('name','ApexPayments')->firstOrFail();
$svc = app(DialerImportedLeadsService::class);

$ryan = User::whereRaw('LOWER(email)=?',['ryan@apexonepayments.com'])->firstOrFail();
$tom = User::whereRaw('LOWER(email)=?',['tomhanderson@apexonepayments.com'])->firstOrFail();
$nina = User::whereRaw('LOWER(email)=?',['nina@apexonepayments.com'])->firstOrFail();

$ryanPage = $svc->paginate($ws, ['assigned_user_id'=>$ryan->id,'pool'=>'assigned'], 0, 50);
$tomPage = $svc->paginate($ws, ['assigned_user_id'=>$tom->id,'pool'=>'assigned'], 0, 50);
$ninaPage = $svc->paginate($ws, ['assigned_user_id'=>$nina->id,'pool'=>'assigned'], 0, 50);

$ryanIds = collect($ryanPage['leads'])->pluck('id');
$tomIds = collect($tomPage['leads'])->pluck('id');
$ninaIds = collect($ninaPage['leads'])->pluck('id');

echo 'ryan_total='.$ryanPage['total']."\n";
echo 'tom_total='.$tomPage['total']."\n";
echo 'nina_total='.$ninaPage['total']."\n";
echo 'ryan_tom_overlap='.$ryanIds->intersect($tomIds)->count()."\n";
echo 'ryan_nina_overlap='.$ryanIds->intersect($ninaIds)->count()."\n";
echo 'tom_nina_overlap='.$tomIds->intersect($ninaIds)->count()."\n";

$code = file_get_contents(__DIR__.'/../app/Services/Communications/DialerImportedLeadsService.php');
echo 'strict_owner='.(str_contains($code, "where('workflow_leads.assigned_user_id', (int) \$filters['assigned_user_id'])") ? 'yes':'no')."\n";
$ctrl = file_get_contents(__DIR__.'/../app/Http/Controllers/CommunicationsHubController.php');
echo 'tl_scoped='.(str_contains($ctrl, "in_array(\$tier, ['agent', 'team_lead'], true)") ? 'yes':'no')."\n";
"""


def main() -> int:
    (ROOT / "deploy/_verify_lead_isolation.php").write_text(VERIFY, encoding="utf-8")
    ssh = connect()
    try:
        upload_files(
            ssh,
            [(ROOT / f, f) for f in FILES]
            + [(ROOT / "deploy/_verify_lead_isolation.php", "scripts/_verify_lead_isolation.php")],
            REMOTE_APP,
        )
        out = sudo_run(
            ssh,
            f"""
cd {REMOTE_APP}
sudo -u www-data php artisan optimize:clear
sudo -u www-data php scripts/_verify_lead_isolation.php
rm -f scripts/_verify_lead_isolation.php
""",
            check=False,
        )
        sys.stdout.buffer.write((out or "").encode("utf-8", "replace"))
        sys.stdout.buffer.write(b"\n")
        print("Lead ownership isolation deployed.")
        return 0
    finally:
        ssh.close()
        p = ROOT / "deploy/_verify_lead_isolation.php"
        if p.exists():
            p.unlink()


if __name__ == "__main__":
    raise SystemExit(main())
