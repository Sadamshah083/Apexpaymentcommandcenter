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
    print(sudo_run(ssh, "grep -n 'function looksLikePhoneNumber' /var/www/apexone/app/Support/LeadContactDisplay.php || echo MISSING_ON_SERVER"))
    print(sudo_run(ssh, r'''cd /var/www/apexone && php -r '
require "vendor/autoload.php";
$app=require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$w=App\Models\Workflow::find(20);
if(!$w){echo "no wf"; exit;}
$path=Illuminate\Support\Facades\Storage::disk("local")->path($w->file_path);
echo "path=$path exists=".(is_file($path)?"yes":"no")."\n";
$reader=PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
$reader->setReadDataOnly(true);
$ss=$reader->load($path);
$sheet=$ss->getActiveSheet();
for($r=1;$r<=8;$r++){
  $vals=[];
  foreach($sheet->getRowIterator($r,$r) as $row){
    $it=$row->getCellIterator(); $it->setIterateOnlyExistingCells(false);
    $c=0;
    foreach($it as $cell){ if($c++>8) break; $vals[]=substr((string)$cell->getValue(),0,40); }
  }
  echo "R$r: ".json_encode($vals)."\n";
}
' '''))
finally:
    ssh.close()
