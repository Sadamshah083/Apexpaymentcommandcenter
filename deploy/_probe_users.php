<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "workspaces=".App\Models\Workspace::count().PHP_EOL;
foreach (App\Models\Workspace::query()->get() as $ws) {
  echo "WS id={$ws->id} name={$ws->name} users=".$ws->users()->count()." active=".$ws->users()->wherePivot('status','active')->count().PHP_EOL;
  foreach ($ws->users()->get() as $u) {
    echo "  id={$u->id} name={$u->name} email={$u->email} role={$u->pivot->role} status={$u->pivot->status} ext=".($u->pivot->morpheus_extension_num ?? '').PHP_EOL;
  }
}
echo "all_users=".App\Models\User::count().PHP_EOL;
foreach (App\Models\User::orderBy('id')->limit(80)->get() as $u) {
  echo "U id={$u->id} name={$u->name} email={$u->email}".PHP_EOL;
}
if (Illuminate\Support\Facades\Schema::hasTable('workspace_user')) {
  $rows = Illuminate\Support\Facades\DB::table('workspace_user')->orderBy('user_id')->get();
  echo "workspace_user_rows=".$rows->count().PHP_EOL;
  foreach ($rows as $r) {
    echo "  wu ws={$r->workspace_id} user={$r->user_id} role={$r->role} status={$r->status} ext=".($r->morpheus_extension_num ?? '').PHP_EOL;
  }
}
$ws = App\Models\Workspace::query()->orderBy('id')->first();
$roster = app(App\Services\Communications\CommunicationsAgentService::class)->listMonitorableDirectory($ws);
echo "monitorable_roster=".count($roster).PHP_EOL;
foreach ($roster as $a) {
  echo " roster id={$a['user_id']} name={$a['name']} role={$a['role']} ext=".($a['morpheus_extension_num'] ?? '').PHP_EOL;
}
