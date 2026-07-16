<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Auth;

$wsAgents = Workspace::find(2);
$admin = User::find(1);
$super = User::find(2);

if ($wsAgents && $admin) {
    if (! $wsAgents->users()->where('user_id', $admin->id)->exists()) {
        $wsAgents->users()->attach($admin->id, [
            'role' => 'admin',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        echo "attached admin to workspace 2\n";
    } else {
        echo "admin already on workspace 2\n";
    }
    $admin->update(['current_workspace_id' => 2]);
    echo "admin current_workspace_id={$admin->fresh()->current_workspace_id}\n";
}
if ($wsAgents && $super) {
    $super->update(['current_workspace_id' => 2]);
    echo "superadmin current_workspace_id={$super->fresh()->current_workspace_id}\n";
}

Auth::login($admin);
$mon = app(App\Services\Communications\CallMonitoringService::class);
$resolved = $mon->resolveWorkspaceForMonitoring($admin);
echo "resolved_workspace={$resolved->id} {$resolved->name}\n";
$snap = $mon->snapshot(null, light: true);
echo "snapshot total=".($snap['summary']['total'] ?? 0)." not_logged_in=".($snap['summary']['not_logged_in'] ?? 0)."\n";
foreach (($snap['tables']['not_logged_in'] ?? []) as $r) {
    echo "  USER={$r['user']} STATION={$r['station']} ROLE={$r['role_label']}\n";
}
