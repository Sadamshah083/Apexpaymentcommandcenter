#!/usr/bin/env python3
import os, sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
os.environ.setdefault("DEPLOY_PASSWORD", "balitech1")
import deploy._ssh as s
s.HOST = "203.215.161.236"
s.USER = "ateg"
s.PASSWORD = "balitech1"
s.REMOTE_APP = "/var/www/apexone"
from deploy._ssh import connect, sudo_run, upload_files

php = r'''<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

echo "php_ok\n";
echo "app_errors_table=".(Schema::hasTable('application_errors')?'yes':'no')."\n";
if (Schema::hasTable('application_errors')) {
    $rows = DB::table('application_errors')->orderByDesc('id')->limit(8)->get(['id','message','path','count','last_seen_at']);
    foreach ($rows as $r) {
        echo "ERR {$r->id} c={$r->count} ".substr(str_replace("\n"," ",(string)$r->message),0,120)." | {$r->path}\n";
    }
}
if (Schema::hasTable('workspace_sync_events')) {
    echo "sync_cols=".implode(',', Schema::getColumnListing('workspace_sync_events'))."\n";
}
echo "role_typo=".(str_contains(file_get_contents(__DIR__.'/../app/Services/Workspace/WorkspaceMemberService.php'), 'if (role ===') ? 'YES_BUG' : 'fixed')."\n";
'''
path = ROOT / "deploy" / "_probe_errors_jwt.php"
path.write_text(php, encoding="utf-8", newline="\n")
ssh = connect()
upload_files(ssh, [(path, "deploy/_probe_errors_jwt.php")], app_root="/var/www/apexone")
out = sudo_run(ssh, "cd /var/www/apexone && uptime && systemctl is-active nginx && (systemctl is-active php8.3-fpm || systemctl is-active php8.2-fpm || systemctl is-active php-fpm) && curl -s -o /dev/null -w 'local_HTTP:%{http_code} TIME:%{time_total}\\n' --max-time 25 http://127.0.0.1/ && sudo -u www-data php deploy/_probe_errors_jwt.php")
print(out.encode("ascii", "replace").decode("ascii"))
ssh.close()
