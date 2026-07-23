#!/usr/bin/env python3
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

from deploy._ssh import connect, sudo_run

PHP = r"""<?php
require '/var/www/apexone/vendor/autoload.php';
$app = require '/var/www/apexone/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo 'db=' . config('database.default') . ' queue=' . config('queue.default')
    . ' workers=' . config('queue.workers') . PHP_EOL;
echo 'model=' . config('workflow_enrichment.gemini_model')
    . ' followup=' . json_encode(config('workflow_enrichment.follow_up_enabled'))
    . ' webqueries=' . config('workflow_enrichment.web_search_queries') . PHP_EOL;
echo 'jobs=' . Illuminate\Support\Facades\DB::table('jobs')->count()
    . ' failed_jobs=' . Illuminate\Support\Facades\DB::table('failed_jobs')->count() . PHP_EOL;
foreach (App\Models\Workflow::whereIn('id', [18, 19, 20])->get() as $w) {
    $counts = $w->leads()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
    echo $w->id . ' ' . $w->status . ' total=' . $w->total_leads
        . ' enriched=' . $w->enriched_leads . ' failed=' . $w->failed_leads
        . ' counts=' . json_encode($counts) . ' updated=' . $w->updated_at . PHP_EOL;
}
$errors = App\Models\WorkflowLead::query()
    ->where('workflow_id', 18)
    ->where('status', 'failed')
    ->selectRaw('LEFT(error_message, 180) as err, count(*) as n')
    ->groupBy('err')
    ->orderByDesc('n')
    ->limit(10)
    ->pluck('n', 'err');
echo 'errors=' . json_encode($errors) . PHP_EOL;
"""

ssh = connect()
try:
    sftp = ssh.open_sftp()
    with sftp.file("/tmp/apex_enrichment_perf.php", "w") as f:
        f.write(PHP)
    sftp.close()
    print("=== app ===")
    print(sudo_run(ssh, "php /tmp/apex_enrichment_perf.php", check=False))
    print("=== service ===")
    print(sudo_run(ssh, "systemctl cat apexone-queue.service; systemctl status apexone-queue.service --no-pager -l | head -50", check=False))
    print("=== processes ===")
    print(sudo_run(ssh, "pgrep -af 'artisan queue:(work|pool)'", check=False))
    print("=== resources ===")
    print(sudo_run(ssh, "nproc; free -h; uptime", check=False))
    print("=== recent queue log ===")
    print(sudo_run(ssh, "cd /var/www/apexone && tail -80 storage/logs/queue-pool.log 2>/dev/null", check=False))
    print("=== provider warnings ===")
    print(sudo_run(
        ssh,
        "cd /var/www/apexone && tail -3000 storage/logs/laravel.log | "
        "grep -E 'Gemini failed|WorkflowExtractor failed|rate.limit|429|quota' | tail -80",
        check=False,
    ))
    print("=== targeted tests ===")
    print(sudo_run(
        ssh,
        "cd /var/www/apexone && php artisan test --filter=ProcessLeadJobAssignmentTest 2>&1 | tail -30",
        check=False,
    ))
finally:
    ssh.close()
