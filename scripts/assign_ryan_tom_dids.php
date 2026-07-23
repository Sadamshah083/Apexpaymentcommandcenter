<?php

/**
 * Assign Morpheus extensions + billing DIDs to Ryan and Tomhanderson (B2B Closers).
 */

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

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
    fwrite(STDERR, "Morpheus API not configured.\n");
    exit(1);
}

$hub = app(MorpheusHubService::class);
$hub->bustCache();

$listed = $api->listExtensions(['limit' => 200]);
$extensions = collect($listed['extensions'] ?? [])->keyBy(fn (array $ext) => (string) ($ext['extension_num'] ?? ''));
echo 'Loaded '.$extensions->count()." Morpheus extensions from API.\n";

$assignments = [
    'ryan@apexonepayments.com' => '1018',
    'tomhanderson@apexonepayments.com' => '1019',
];

$billing = config('morpheus_billing_dids.extensions', []);
$linked = 0;

foreach ($assignments as $email => $extNum) {
    $user = User::query()->whereRaw('LOWER(email) = ?', [strtolower($email)])->first();
    if (! $user) {
        echo "Skip {$email}: user not found.\n";
        continue;
    }

    if (! $workspace->users()->where('user_id', $user->id)->exists()) {
        echo "Skip {$email}: not in ApexPayments.\n";
        continue;
    }

    // Refuse to steal an extension already linked to someone else.
    $taken = $workspace->users()
        ->wherePivot('morpheus_extension_num', (string) $extNum)
        ->where('users.id', '!=', $user->id)
        ->first();
    if ($taken) {
        echo "Skip {$email}: ext {$extNum} already linked to {$taken->name}.\n";
        continue;
    }

    $ext = $extensions->get((string) $extNum);
    if (! $ext) {
        echo "Skip {$email}: Morpheus extension {$extNum} not found.\n";
        continue;
    }

    $did = $billing[(string) $extNum] ?? null;
    if (filled($did) && filled($ext['id'] ?? null)) {
        $patch = $api->updateExtension((string) $ext['id'], array_filter([
            'is_dialer_agent' => true,
            'status' => 'active',
            'override_campaign_cid' => true,
            'caller_id_num' => $did,
            'outbound_cid_num' => $did,
            'caller_id_name' => $user->name,
            'outbound_cid_name' => $user->name,
        ], fn ($v) => $v !== null && $v !== ''));
        if (isset($patch['error']) && ! isset($patch['id'])) {
            echo "  Warning: DID patch failed for {$extNum}: {$patch['error']}\n";
        } else {
            echo "  DID {$did} patched on ext {$extNum}\n";
        }
    }

    $workspace->users()->updateExistingPivot($user->id, [
        'morpheus_user_id' => filled($ext['user_id'] ?? null) ? (string) $ext['user_id'] : null,
        'morpheus_extension_id' => $ext['id'] ?? null,
        'morpheus_extension_num' => (string) ($ext['extension_num'] ?? $extNum),
    ]);

    $role = $user->getWorkspaceRole($workspace->id);
    echo "Linked {$user->name} <{$email}> (".SalesOps::roleLabel($role).") -> ext {$extNum}".($did ? " DID {$did}" : '')."\n";
    $linked++;
}

$hub->bustCache();
echo "\nLinked {$linked} account(s).\n";

foreach ($assignments as $email => $extNum) {
    $user = User::query()->whereRaw('LOWER(email) = ?', [strtolower($email)])->first();
    if (! $user) {
        continue;
    }
    $pivot = $workspace->users()->where('user_id', $user->id)->first()?->pivot;
    $liveExt = $extensions->get((string) ($pivot->morpheus_extension_num ?? $extNum));
    $liveDid = $liveExt['outbound_cid_num'] ?? $liveExt['caller_id_num'] ?? null;
    $configDid = $billing[(string) ($pivot->morpheus_extension_num ?? $extNum)] ?? '-';
    echo sprintf(
        "VERIFY %s role=%s ext=%s config_did=%s live_did=%s\n",
        $user->name,
        $pivot->role ?? '-',
        $pivot->morpheus_extension_num ?? '-',
        $configDid,
        $liveDid ?: '-'
    );
}
