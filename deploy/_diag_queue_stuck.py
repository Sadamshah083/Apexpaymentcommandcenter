#!/usr/bin/env python3
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

from deploy._ssh import REMOTE_APP, connect, sudo_run_batch, upload_files

PHP = r"""<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;

echo "=== jobs ===\n";
foreach (DB::table('jobs')->orderBy('id')->get() as $j) {
  $payload = json_decode($j->payload, true) ?: [];
  $display = $payload['displayName'] ?? '?';
  $attempts = $j->attempts;
  $reserved = $j->reserved_at ? date('c', $j->reserved_at) : '-';
  $available = date('c', $j->available_at);
  echo "id={$j->id} queue={$j->queue} attempts={$attempts} reserved={$reserved} avail={$available} name={$display}\n";
}

echo "\n=== failed (last 5) ===\n";
foreach (DB::table('failed_jobs')->orderByDesc('id')->limit(5)->get() as $f) {
  $payload = json_decode($f->payload, true) ?: [];
  echo "id={$f->id} queue={$f->queue} name=".($payload['displayName']??'?')." at={$f->failed_at}\n";
  echo "exc=".substr(str_replace("\n"," ",$f->exception),0,300)."\n";
}

echo "\n=== redis/horizon? ===\n";
echo "QUEUE_CONNECTION=".env('QUEUE_CONNECTION')."\n";
echo "cache_driver=".config('cache.default')."\n";

echo "\n=== ps queue ===\n";
echo shell_exec("ps aux | grep -E 'queue:work|horizon|artisan' | grep -v grep") ?? '';

echo "\n=== supervisor ===\n";
echo shell_exec("sudo supervisorctl status 2>/dev/null || systemctl status supervisor --no-pager 2>/dev/null | head -40") ?? '';
"""

(ROOT / "deploy/_diag_queue.php").write_text(PHP, encoding="utf-8")
ssh = connect()
try:
    upload_files(ssh, [(ROOT / "deploy/_diag_queue.php", "scripts/_diag_queue.php")], app_root=REMOTE_APP)
    out = sudo_run_batch(ssh, [
        f"cd {REMOTE_APP} && sudo -u www-data php scripts/_diag_queue.php",
        f"tail -n 80 {REMOTE_APP}/storage/logs/laravel.log 2>/dev/null | sed 's/\\x1b\\[[0-9;]*m//g' | tail -n 80",
        f"rm -f {REMOTE_APP}/scripts/_diag_queue.php",
    ])
    print(out.encode("ascii", "replace").decode("ascii"))
finally:
    ssh.close()
    p = ROOT / "deploy/_diag_queue.php"
    if p.exists():
        p.unlink()
