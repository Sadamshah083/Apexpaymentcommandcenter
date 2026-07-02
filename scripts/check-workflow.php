<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Workflow;
use Illuminate\Support\Facades\DB;

$id = (int) ($argv[1] ?? 1);
$w = Workflow::find($id);
if ($w) {
    echo "status={$w->status}\n";
    echo "total={$w->total_leads}\n";
    echo "enriched={$w->enriched_leads}\n";
    echo "failed={$w->failed_leads}\n";
    echo "processed={$w->processed_leads}\n";
}
echo 'jobs='.DB::table('jobs')->count()."\n";
echo 'failed_jobs='.DB::table('failed_jobs')->count()."\n";
$rows = DB::table('workflow_leads')->where('workflow_id', $id)->select('status', DB::raw('count(*) as c'))->groupBy('status')->get();
foreach ($rows as $r) {
    echo "{$r->status}={$r->c}\n";
}
