<?php

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\Workspace;
use App\Services\Communications\MorpheusHubService;
use App\Services\Integrations\ZoomApiService;
use App\Support\SalesOps;

$workspace = Workspace::where('name', 'ApexPayments')->first();
if (! $workspace) {
    fwrite(STDERR, "ApexPayments workspace not found.\n");
    exit(1);
}

$api = app(ZoomApiService::class);
if (! $api->isConfigured()) {
    fwrite(STDERR, "Morpheus API not configured (MORPHEUS_HOST / MORPHEUS_API_KEY).\n");
    exit(1);
}

$status = $api->connectionStatus();
echo 'Connection: '.($status['connected'] ? 'OK' : 'FAIL').' — '.($status['message'] ?? '')."\n";
if (! ($status['connected'] ?? false)) {
    exit(1);
}

$hub = app(MorpheusHubService::class);
$extensions = collect($hub->extensions())->keyBy(fn (array $ext) => (string) ($ext['extension_num'] ?? ''));
$users = collect($hub->users())->keyBy('id');

echo 'Morpheus extensions available: '.$extensions->count()."\n";

$portalRoles = config('sales_ops.portal_roles', []);
$agents = $workspace->users()
    ->wherePivot('status', 'active')
    ->wherePivotIn('role', $portalRoles)
    ->orderBy('name')
    ->get();

if ($agents->isEmpty()) {
    fwrite(STDERR, "No portal agents in ApexPayments workspace.\n");
    exit(1);
}

$extensionNums = collect(range(1001, 1020))->values();
$linked = 0;

foreach ($agents as $index => $agent) {
    $pivot = $agent->pivot;
    $targetExt = $extensionNums[$index] ?? null;
    if (! $targetExt) {
        echo "Skip {$agent->name}: no extension slot left in 1001-1020 range.\n";
        continue;
    }

    $ext = $extensions->get((string) $targetExt);
    if (! $ext) {
        echo "Skip {$agent->name}: Morpheus extension {$targetExt} not found.\n";
        continue;
    }

    $morpheusUserId = $ext['user_id'] ?? $pivot->morpheus_user_id;
    if ($morpheusUserId && $users->has($morpheusUserId)) {
        $morpheusUserId = (string) $morpheusUserId;
    }

    $workspace->users()->updateExistingPivot($agent->id, [
        'morpheus_user_id' => $morpheusUserId,
        'morpheus_extension_id' => $ext['id'] ?? $pivot->morpheus_extension_id,
        'morpheus_extension_num' => (string) ($ext['extension_num'] ?? $targetExt),
    ]);

    echo "Linked {$agent->name} (".SalesOps::roleLabel($pivot->role).") -> ext {$targetExt}\n";
    $linked++;
}

$hub->bustCache();

echo "\nLinked {$linked} agent(s) to Morpheus extensions.\n";
echo "Default dialer extension: ".config('integrations.communications.default_caller_id', '1001')."\n";
