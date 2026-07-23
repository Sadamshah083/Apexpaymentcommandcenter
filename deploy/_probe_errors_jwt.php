<?php
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
