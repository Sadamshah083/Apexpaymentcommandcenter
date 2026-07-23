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
m.REMOTE_APP = "/var/www/apexone"
from deploy._ssh import connect, sudo_run

PHP = r"""<?php
require '/var/www/apexone/vendor/autoload.php';
$app = require '/var/www/apexone/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
foreach (App\Models\Workflow::whereIn('id', [18, 19, 20])->get() as $w) {
    echo $w->id.' '.$w->status.' total='.$w->total_leads.' enriched='.$w->enriched_leads
        .' map_biz='.(($w->column_mapping['business_name'] ?? 'null'))
        .' err='.substr((string)$w->error_message, 0, 120).PHP_EOL;
}
"""

ssh = connect()
try:
    sftp = ssh.open_sftp()
    with sftp.file("/tmp/apex_wf_status.php", "w") as f:
        f.write(PHP)
    sftp.close()
    print(sudo_run(ssh, "php /tmp/apex_wf_status.php", check=False))
finally:
    ssh.close()
