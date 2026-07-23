#!/usr/bin/env python3
from __future__ import annotations
import os, sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
os.environ.setdefault("DEPLOY_PASSWORD", "balitech1")
import deploy._ssh as m
m.HOST="203.215.161.236"; m.USER="ateg"; m.PASSWORD="balitech1"; m.REMOTE_APP="/var/www/apexone"
from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files
PHP = r"""<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$rows = DB::table('telescope_entries')
  ->where('type','exception')
  ->orderByDesc('sequence')
  ->limit(30)
  ->get(['sequence','family_hash','content','created_at']);
foreach ($rows as $r) {
  $c = is_string($r->content) ? json_decode($r->content, true) : (array)$r->content;
  $msg = $c['message'] ?? '';
  $file = $c['file'] ?? ($c['trace'][0]['file'] ?? '');
  if (str_contains($msg, 'campaigns') || str_contains($msg, 'recording_status') || str_contains($msg, 'disposition') || str_contains($msg, 'OpenRouter') || str_contains($msg, 'role')) {
    echo substr((string)$r->created_at,0,19)." | ".substr($msg,0,120)." | ".$file."\n";
  }
}
echo "--- failed SQL for campaigns ---\n";
$q = DB::table('telescope_entries')
  ->where('type','query')
  ->where('content','like','%from `campaigns`%')
  ->orderByDesc('sequence')
  ->limit(5)
  ->get(['content','created_at']);
foreach ($q as $r) {
  $c = is_string($r->content) ? json_decode($r->content, true) : [];
  echo substr((string)$r->created_at,0,19)." | ".substr(($c['sql'] ?? json_encode($c)),0,200)."\n";
}
"""
(ROOT/'deploy/_probe_tel.php').write_text(PHP, encoding='utf-8')
ssh=connect()
try:
    upload_files(ssh, [(ROOT/'deploy/_probe_tel.php','scripts/_probe_tel.php')])
    out=sudo_run(ssh, f'cd {REMOTE_APP} && sudo -u www-data php scripts/_probe_tel.php', check=False)
    sys.stdout.buffer.write((out or '').encode('utf-8','replace')); print()
    sudo_run(ssh, f'rm -f {REMOTE_APP}/scripts/_probe_tel.php', check=False)
finally:
    ssh.close()
    p=ROOT/'deploy/_probe_tel.php'
    if p.exists(): p.unlink()
