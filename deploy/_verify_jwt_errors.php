<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ApplicationError;
use App\Models\User;
use App\Services\Auth\MemberJwtService;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;

echo 'jwt_secret_set='.(filled(config('jwt.secret')) ? 'yes' : 'no')."\n";
echo 'jwt_ttl_hours='.config('jwt.ttl_hours')."\n";

$user = User::query()->orderBy('id')->first();
if ($user) {
    $svc = app(MemberJwtService::class);
    $token = $svc->issue($user);
    $payload = $svc->parse($token);
    $expIn = (int) ($payload['exp'] ?? 0) - time();
    $ok = is_array($payload) && $expIn > 8 * 3600 && $expIn <= 9 * 3600 + 60;
    echo 'jwt_issue='.($ok ? 'ok' : 'fail').' exp_in='.$expIn."s\n";
}

$src = file_get_contents(__DIR__.'/../app/Services/Workspace/WorkspaceMemberService.php');
echo 'role_typo='.(str_contains($src, 'if (role ===') ? 'BUG' : 'fixed')."\n";
$cds = file_get_contents(__DIR__.'/../app/Services/Communications/CommunicationsDataService.php');
echo 'recording_status_safe='.(str_contains($cds, "recording_status'] ??") ? 'yes' : 'check')."\n";

if (Schema::hasTable('application_errors')) {
    $before = ApplicationError::query()->count();
    $ctl = new \App\Http\Controllers\ErrorMonitoringController();
    $m = new ReflectionMethod($ctl, 'purgeResolvedFingerprints');
    $m->setAccessible(true);
    $removed = (int) $m->invoke($ctl);
    $after = ApplicationError::query()->count();
    echo 'errors_before='.$before.' purged='.$removed.' after='.$after."\n";
}
echo "OK\n";
