<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$ws = App\Models\Workspace::query()->orderBy('id')->first();
echo 'workspace='.($ws?->id ?? 'none').' name='.($ws?->name ?? '').PHP_EOL;

$agentsSvc = app(App\Services\Communications\CommunicationsAgentService::class);
$local = $agentsSvc->listLocalExtensionDirectory($ws);
echo 'local_agents='.count($local).PHP_EOL;
foreach (array_slice($local, 0, 20) as $a) {
    echo ' agent id='.$a['user_id'].' name='.$a['name'].' role='.$a['role'].' ext='.$a['morpheus_extension_num'].PHP_EOL;
}

$allUsers = $ws->users()->wherePivot('status', 'active')->get(['users.id', 'users.name', 'users.email']);
echo 'active_workspace_users='.$allUsers->count().PHP_EOL;
foreach ($allUsers as $u) {
    $role = (string) ($u->pivot->role ?? '');
    $ext = (string) ($u->pivot->morpheus_extension_num ?? '');
    $mon = App\Services\Communications\AgentPresenceService::isMonitorableRole($role) ? 'yes' : 'no';
    echo ' user id='.$u->id.' name='.$u->name.' role='.$role.' ext='.$ext.' monitorable='.$mon.PHP_EOL;
}

$snap = app(App\Services\Communications\CallMonitoringService::class)->snapshot($ws, light: false);
echo 'rows='.count($snap['rows'] ?? []).' summary='.json_encode($snap['summary'] ?? []).PHP_EOL;
echo 'warnings='.json_encode($snap['warnings'] ?? []).PHP_EOL;
foreach (array_slice($snap['rows'] ?? [], 0, 25) as $r) {
    echo ' row user='.($r['user'] ?? '').' status='.($r['status'] ?? '').' bucket='.($r['bucket'] ?? '').' station='.($r['station'] ?? '').PHP_EOL;
}

$online = app(App\Services\Communications\AgentPresenceService::class)->listOnline($ws);
echo 'online='.count($online).PHP_EOL;
foreach (array_slice($online, 0, 15) as $o) {
    echo ' online id='.$o['user_id'].' name='.($o['name'] ?? '').' role='.($o['role'] ?? '').' on_call='.(($o['on_call'] ?? false) ? '1' : '0').PHP_EOL;
}
