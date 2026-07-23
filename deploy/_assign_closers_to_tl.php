<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceMemberService;

$ws = Workspace::find(2);
$actor = User::platformSuperAdmin();
$svc = app(WorkspaceMemberService::class);

foreach (['ElijahMorgan', 'Jacob Khan'] as $name) {
    $user = User::where('name', $name)->first();
    if (! $user) {
        echo "skip {$name}\n";
        continue;
    }
    $svc->updateMemberRole($ws, $actor, $user, 'closer');
    $pivot = $ws->users()->where('user_id', $user->id)->first()->pivot;
    echo "{$name} role={$pivot->role} lead=".($pivot->team_lead_user_id ?: 'null')."\n";
}
echo "OK\n";
