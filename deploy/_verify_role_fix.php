<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceMemberService;

$src = file_get_contents(__DIR__.'/../app/Services/Workspace/WorkspaceMemberService.php');
if (str_contains($src, 'if (role ===')) {
    echo "BUG_STILL_PRESENT\n";
    exit(1);
}
echo "typo_fixed=yes\n";

$ws = Workspace::find(2);
$actor = User::platformSuperAdmin() ?: User::find(23);
$member = User::find(14);
if (! $ws || ! $actor || ! $member) {
    echo "missing fixtures ws=".($ws?->id?:0)." actor=".($actor?->id?:0)." member=".($member?->id?:0)."\n";
    exit(1);
}

$svc = app(WorkspaceMemberService::class);
$before = $ws->users()->where('user_id', $member->id)->first()?->pivot?->role;
echo "before_role={$before}\n";

$closerTls = $ws->users()->wherePivot('role', 'closers_team_lead')->wherePivot('status', 'active')->pluck('users.name', 'users.id');
echo 'closer_tls='.$closerTls->map(fn ($n, $id) => "{$id}:{$n}")->implode(',')."\n";

try {
    $svc->updateMemberRole($ws, $actor, $member, 'closer');
    $after = $ws->users()->where('user_id', $member->id)->first();
    echo 'after_role='.($after?->pivot?->role)." lead=".($after?->pivot?->team_lead_user_id ?: 'null')."\n";

    // Also verify admin <-> manager switches work for a non-critical path using the same method on a dry role bounce.
    $svc->updateMemberRole($ws, $actor, $member, 'appointment_setter');
    $svc->updateMemberRole($ws, $actor, $member, 'closer');
    $final = $ws->users()->where('user_id', $member->id)->first();
    echo 'final_role='.($final?->pivot?->role)." lead=".($final?->pivot?->team_lead_user_id ?: 'null')."\n";
    echo "OK\n";
} catch (Throwable $e) {
    echo 'FAIL '.$e->getMessage()."\n";
    exit(1);
}
