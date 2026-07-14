#!/usr/bin/env php
<?php

/**
 * Assign Morpheus extensions 1011–1018 to the new Team Lead / Agent accounts.
 * Uses live API list (not stale hub cache).
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

$listed = $api->listExtensions(['limit' => 100]);
$extensions = collect($listed['extensions'] ?? [])->keyBy(fn (array $ext) => (string) ($ext['extension_num'] ?? ''));

echo 'Loaded '. $extensions->count()." Morpheus extensions from API.\n";

$assignments = [
    'ElijahMorgan' => '1011',
    'damonpeterson' => '1012',
    'JamesHenderson' => '1013',
    'tonnynewman' => '1014',
    'Jacob Khan' => '1015',
    'Nina Jones' => '1016',
    'SophiaHeather' => '1017',
    'Hannah' => '1018',
];

$billing = config('morpheus_billing_dids.extensions', []);
$linked = 0;

foreach ($assignments as $username => $extNum) {
    $user = User::where('name', $username)->first();
    if (! $user) {
        echo "Skip {$username}: user not found.\n";
        continue;
    }

    if (! $workspace->users()->where('user_id', $user->id)->exists()) {
        echo "Skip {$username}: not in ApexPayments workspace.\n";
        continue;
    }

    $ext = $extensions->get((string) $extNum);
    if (! $ext) {
        echo "Skip {$username}: Morpheus extension {$extNum} not found.\n";
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
        ]));
        if (isset($patch['error']) && ! isset($patch['id'])) {
            echo "  Warning: DID patch failed for {$extNum}: {$patch['error']}\n";
        }
    }

    $workspace->users()->updateExistingPivot($user->id, [
        'morpheus_user_id' => filled($ext['user_id'] ?? null) ? (string) $ext['user_id'] : null,
        'morpheus_extension_id' => $ext['id'] ?? null,
        'morpheus_extension_num' => (string) ($ext['extension_num'] ?? $extNum),
    ]);

    $role = $user->getWorkspaceRole($workspace->id);
    echo "Linked {$username} (".SalesOps::roleLabel($role).") -> ext {$extNum}".($did ? " DID {$did}" : '')."\n";
    $linked++;
}

$hub->bustCache();

echo "\nLinked {$linked} account(s).\n";

foreach (array_keys($assignments) as $username) {
    $user = User::where('name', $username)->first();
    if (! $user) {
        continue;
    }
    $pivot = $workspace->users()->where('user_id', $user->id)->first()?->pivot;
    echo sprintf("VERIFY %s -> ext=%s id=%s\n", $username, $pivot->morpheus_extension_num ?? '-', $pivot->morpheus_extension_id ?? '-');
}
