#!/usr/bin/env python3
from __future__ import annotations
import os, sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
os.environ.setdefault("DEPLOY_PASSWORD", "balitech1")
import deploy._ssh as m
m.HOST = "203.215.161.236"; m.USER = "ateg"; m.PASSWORD = "balitech1"; m.REMOTE_APP = "/var/www/apexone"
from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

PHP = r"""<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
echo "=== recent workflows ===\n";
foreach (DB::table('workflows')->orderByDesc('id')->limit(8)->get() as $w) {
  echo "id={$w->id} status={$w->status} mode={$w->processing_mode} total={$w->total_leads} enriched={$w->enriched_leads} file=".basename((string)$w->original_filename)."\n";
}
echo "\n=== job types ===\n";
$names=[];
foreach (DB::table('jobs')->get() as $j) {
  $p=json_decode($j->payload,true)?:[];
  $n=$p['displayName']??'?';
  $names[$n]=($names[$n]??0)+1;
}
echo json_encode($names)."\n";
"""
(ROOT/"deploy/_probe_modes.php").write_text(PHP, encoding="utf-8")
ssh=connect()
try:
    upload_files(ssh, [(ROOT/"deploy/_probe_modes.php","scripts/_probe_modes.php")], app_root=REMOTE_APP)
    print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php scripts/_probe_modes.php", check=False).encode("ascii","replace").decode("ascii"))
    sudo_run(ssh, f"rm -f {REMOTE_APP}/scripts/_probe_modes.php", check=False)
finally:
    ssh.close()
    p=ROOT/"deploy/_probe_modes.php"
    if p.exists(): p.unlink()
