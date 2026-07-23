<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

if (! Schema::hasColumn('users', 'password_hint')) {
    echo "MISSING password_hint column\n";
    exit(1);
}

// Prefer a single platform Super Admin (lowest id with super_admin pivot).
$saIds = DB::table('workspace_user')
    ->where('role', 'super_admin')
    ->where('status', 'active')
    ->orderBy('user_id')
    ->pluck('user_id')
    ->unique()
    ->values();

$keepId = $saIds->first();
if ($keepId) {
    $extra = $saIds->filter(fn ($id) => (int) $id !== (int) $keepId)->values();
    foreach ($extra as $extraId) {
        DB::table('workspace_user')
            ->where('user_id', $extraId)
            ->where('role', 'super_admin')
            ->update(['role' => 'admin', 'updated_at' => now()]);
        echo "demoted_extra_sa user_id={$extraId} -> admin\n";
    }
}

$super = User::platformSuperAdmin();
echo 'platform_sa='.($super?->email ?? 'none').' id='.($super?->id ?? 0).' is_platform='.($super && $super->isPlatformSuperAdmin() ? 'yes' : 'no')."\n";

$hinted = User::query()->whereNotNull('password_hint')->where('password_hint', '!=', '')->count();
$missing = User::query()->where(function ($q) {
    $q->whereNull('password_hint')->orWhere('password_hint', '');
})->count();
echo "password_hint_filled={$hinted} missing={$missing}\n";

$ws = Workspace::query()->orderBy('id')->first();
if ($ws && $super) {
    echo 'add_workspace_gate='.($super->isPlatformSuperAdmin() ? 'ok' : 'fail')."\n";
    echo 'ws_members='.$ws->users()->count()."\n";
}

echo "OK\n";
