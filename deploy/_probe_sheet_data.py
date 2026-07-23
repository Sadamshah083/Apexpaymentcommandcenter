#!/usr/bin/env python3
from __future__ import annotations
import os, sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
os.environ.setdefault("DEPLOY_PASSWORD", "balitech1")
import deploy._ssh as m
m.HOST="203.215.161.236"; m.USER="ateg"; m.PASSWORD="balitech1"; m.REMOTE_APP="/var/www/apexone"
from deploy._ssh import connect, sudo_run

PHP = r"""<?php
require '/var/www/apexone/vendor/autoload.php';
$app = require '/var/www/apexone/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

foreach ([22,23] as $id) {
  $sample = App\Models\WorkflowLead::where('workflow_id',$id)->whereNull('researched_at')->orderBy('id')->limit(3)->get([
    'id','business_name','owner_name','direct_email','direct_phone','business_phone','input_phone','website','city','state','address','raw_data','status'
  ]);
  echo "=== wf{$id} pending samples ===\n";
  foreach ($sample as $l) {
    $rawKeys = is_array($l->raw_data) ? implode(',', array_keys($l->raw_data)) : gettype($l->raw_data);
    echo "id={$l->id} biz={$l->business_name} owner={$l->owner_name} email={$l->direct_email} phone={$l->direct_phone}|{$l->business_phone}|{$l->input_phone} web={$l->website} city={$l->city} state={$l->state}\n";
    echo "  raw_keys={$rawKeys}\n";
  }
}

// Check openrouter key prefix only
$key = (string) config('openrouter.api_key');
echo "or_key_prefix=" . substr($key, 0, 12) . "... len=" . strlen($key) . "\n";
$gkey = (string) (config('gemini.api_key') ?: env('GEMINI_API_KEY'));
echo "gemini_key_prefix=" . substr($gkey, 0, 8) . "... len=" . strlen($gkey) . "\n";
"""

ssh=connect()
try:
  sftp=ssh.open_sftp()
  with sftp.file('/tmp/apex_sheet.php','w') as f: f.write(PHP)
  sftp.close()
  print(sudo_run(ssh,'php /tmp/apex_sheet.php',check=False))
finally:
  ssh.close()
