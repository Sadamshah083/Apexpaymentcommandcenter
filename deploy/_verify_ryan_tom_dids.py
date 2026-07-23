#!/usr/bin/env python3
"""Re-verify Ryan/Tom DID assignment after patch."""
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

PHP = r"""<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\Workspace;
use App\Services\Communications\MorpheusHubService;
use App\Services\Integrations\ZoomApiService;
use Illuminate\Support\Facades\DB;

$ws = Workspace::where('name', 'ApexPayments')->firstOrFail();
$hub = app(MorpheusHubService::class);
$hub->bustCache();
$api = app(ZoomApiService::class);
$listed = $api->listExtensions(['limit' => 200]);
$exts = collect($listed['extensions'] ?? [])->keyBy(fn ($e) => (string)($e['extension_num'] ?? ''));

foreach (['ryan@apexonepayments.com' => '1018', 'tomhanderson@apexonepayments.com' => '1019'] as $email => $want) {
    $u = User::whereRaw('LOWER(email)=?', [strtolower($email)])->firstOrFail();
    $p = DB::table('workspace_user')->where('workspace_id', $ws->id)->where('user_id', $u->id)->first();
    $e = $exts->get((string)($p->morpheus_extension_num ?? ''));
    echo $u->name
        ." crm_ext=".($p->morpheus_extension_num ?? '-')
        ." want={$want}"
        ." live_out=".($e['outbound_cid_num'] ?? '-')
        ." live_caller=".($e['caller_id_num'] ?? '-')
        ." name=".($e['caller_id_name'] ?? '-')
        ."\n";
}
"""

(ROOT / "deploy/_verify_ryan_tom_dids.php").write_text(PHP, encoding="utf-8")
ssh = connect()
try:
    upload_files(ssh, [(ROOT / "deploy/_verify_ryan_tom_dids.php", "scripts/_verify_ryan_tom_dids.php")], app_root=REMOTE_APP)
    out = sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php scripts/_verify_ryan_tom_dids.php", check=False)
    print((out or "").encode("ascii", "replace").decode("ascii"))
    sudo_run(ssh, f"rm -f {REMOTE_APP}/scripts/_verify_ryan_tom_dids.php", check=False)
finally:
    ssh.close()
    p = ROOT / "deploy/_verify_ryan_tom_dids.php"
    if p.exists():
        p.unlink()
