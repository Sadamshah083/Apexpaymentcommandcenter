#!/usr/bin/env python3
import os, sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
os.environ.setdefault("DEPLOY_PASSWORD", "balitech1")
import deploy._ssh as m
m.HOST = "203.215.161.236"
m.USER = "ateg"
m.PASSWORD = "balitech1"
from deploy._ssh import connect, sudo_run

ssh = connect()
try:
    script = r'''
cd /var/www/apexone && php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$rows = App\Models\Workflow::query()->latest("id")->limit(8)->get(["id","name","status","error_message","total_leads","enriched_leads","original_filename","column_mapping","selected_sheet"]);
foreach ($rows as $w) {
  $map = json_encode($w->column_mapping);
  echo "id={$w->id} status={$w->status} total={$w->total_leads} enriched={$w->enriched_leads} file=".($w->original_filename?: "-")." name={$w->name}\n  err=".($w->error_message ?: "-")."\n  map={$map}\n";
}
'
'''
    print(sudo_run(ssh, script))
finally:
    ssh.close()
