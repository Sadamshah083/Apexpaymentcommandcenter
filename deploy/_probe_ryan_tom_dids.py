#!/usr/bin/env python3
"""Probe Ryan/Tomhanderson DID status + free Morpheus extensions."""
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
use App\Services\Integrations\ZoomApiService;
use Illuminate\Support\Facades\DB;

$ws = Workspace::where('name', 'ApexPayments')->first() ?: Workspace::find(2);
echo "workspace={$ws->id} {$ws->name}\n";

foreach (['ryan@apexonepayments.com', 'tomhanderson@apexonepayments.com', 'damonpeterson@apexonepayments.com'] as $email) {
    $u = User::whereRaw('LOWER(email)=?', [strtolower($email)])->first();
    if (!$u) { echo "MISSING {$email}\n"; continue; }
    $p = DB::table('workspace_user')->where('workspace_id', $ws->id)->where('user_id', $u->id)->first();
    echo "USER {$u->name} <{$u->email}> id={$u->id} role=".($p->role ?? '?')
        ." ext=".($p->morpheus_extension_num ?? '-')
        ." ext_id=".($p->morpheus_extension_id ?? '-')
        ." lead=".($p->team_lead_user_id ?? 'null')."\n";
}

echo "\n=== workspace_user extensions in use ===\n";
$rows = DB::table('workspace_user')
    ->where('workspace_id', $ws->id)
    ->whereNotNull('morpheus_extension_num')
    ->where('morpheus_extension_num', '!=', '')
    ->orderBy('morpheus_extension_num')
    ->get(['user_id', 'role', 'morpheus_extension_num', 'status']);
$used = [];
foreach ($rows as $r) {
    $u = User::find($r->user_id);
    $used[(string)$r->morpheus_extension_num] = true;
    echo "  ext {$r->morpheus_extension_num} role={$r->role} status={$r->status} ".($u->name ?? '?')." <".($u->email ?? '').">\n";
}

$billing = config('morpheus_billing_dids.extensions', []);
echo "\n=== billing DID pool ===\n";
foreach ($billing as $ext => $did) {
    $taken = isset($used[(string)$ext]) ? 'USED' : 'FREE';
    echo "  {$ext} => {$did} {$taken}\n";
}

$api = app(ZoomApiService::class);
$listed = $api->listExtensions(['limit' => 200]);
$exts = collect($listed['extensions'] ?? [])->keyBy(fn ($e) => (string)($e['extension_num'] ?? ''));
echo "\n=== morpheus extensions 1001-1020 ===\n";
for ($n = 1001; $n <= 1020; $n++) {
    $e = $exts->get((string)$n);
    if (!$e) { echo "  {$n} MISSING_ON_MORPHEUS\n"; continue; }
    $did = $e['outbound_cid_num'] ?? $e['caller_id_num'] ?? '-';
    $taken = isset($used[(string)$n]) ? 'CRM_LINKED' : 'CRM_FREE';
    echo "  {$n} id=".($e['id'] ?? '?')." did={$did} status=".($e['status'] ?? '?')." {$taken}\n";
}
"""

(ROOT / "deploy/_probe_ryan_tom_dids.php").write_text(PHP, encoding="utf-8")
ssh = connect()
try:
    upload_files(ssh, [(ROOT / "deploy/_probe_ryan_tom_dids.php", "scripts/_probe_ryan_tom_dids.php")], app_root=REMOTE_APP)
    out = sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php scripts/_probe_ryan_tom_dids.php", check=False)
    sys.stdout.buffer.write((out or "").encode("utf-8", "replace"))
    sys.stdout.buffer.write(b"\n")
    sudo_run(ssh, f"rm -f {REMOTE_APP}/scripts/_probe_ryan_tom_dids.php", check=False)
finally:
    ssh.close()
    p = ROOT / "deploy/_probe_ryan_tom_dids.php"
    if p.exists():
        p.unlink()
