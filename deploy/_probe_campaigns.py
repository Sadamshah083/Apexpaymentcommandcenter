#!/usr/bin/env python3
import os, sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
os.environ.setdefault("DEPLOY_PASSWORD", "balitech1")
import deploy._ssh as m
m.HOST="203.215.161.236"; m.USER="ateg"; m.PASSWORD="balitech1"; m.REMOTE_APP="/var/www/apexone"
from deploy._ssh import connect, sudo_run
PHP=r"""<?php
require '/var/www/apexone/vendor/autoload.php';
$app=require '/var/www/apexone/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$ws=App\Models\Workspace::find(2);
foreach($ws->users()->orderBy('users.id')->get() as $u){
  echo $u->id."\t".$u->name."\trole=".($u->pivot->role)."\tcampaign=".($u->pivot->campaign_id??'')."\ttl=".($u->pivot->team_lead_user_id??'')."\n";
}
echo "campaigns:\n";
foreach(App\Models\LeadCampaign::where('workspace_id',2)->get(['id','name']) as $c){ echo $c->id." ".$c->name."\n"; }
"""
ssh=connect()
try:
  sftp=ssh.open_sftp();
  with sftp.file('/tmp/apex_camp.php','w') as f: f.write(PHP)
  sftp.close()
  print(sudo_run(ssh,'php /tmp/apex_camp.php',check=False))
finally: ssh.close()
