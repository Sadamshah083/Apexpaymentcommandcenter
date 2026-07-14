<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

foreach (Workspace::query()->get(['id', 'name']) as $ws) {
    echo "WS {$ws->id} {$ws->name}".PHP_EOL;
    $rows = DB::table('workspace_user')
        ->where('workspace_id', $ws->id)
        ->where('status', 'active')
        ->get(['user_id', 'role', 'morpheus_extension_num', 'morpheus_extension_id']);
    foreach ($rows as $r) {
        $user = DB::table('users')->where('id', $r->user_id)->value('name');
        echo "  {$user} role={$r->role} ext_num={$r->morpheus_extension_num} ext_id={$r->morpheus_extension_id}".PHP_EOL;
    }
}
echo 'OK'.PHP_EOL;
