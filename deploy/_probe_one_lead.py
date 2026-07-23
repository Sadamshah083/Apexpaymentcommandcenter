#!/usr/bin/env python3
from __future__ import annotations
import os, sys, json
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
$l = App\Models\WorkflowLead::where('workflow_id',22)->whereNull('researched_at')->orderBy('id')->first();
if(!$l){ echo "none\n"; exit; }
$attrs = collect($l->getAttributes())->except(['markdown_report'])->all();
foreach($attrs as $k=>$v){
  if($v===null || $v==='') continue;
  if(is_string($v) && strlen($v)>120) $v=substr($v,0,120).'...';
  echo "$k=".json_encode($v)."\n";
}
"""
ssh=connect()
try:
  sftp=ssh.open_sftp();
  with sftp.file('/tmp/apex_onelead.php','w') as f: f.write(PHP)
  sftp.close()
  print(sudo_run(ssh,'php /tmp/apex_onelead.php',check=False))
finally:
  ssh.close()
