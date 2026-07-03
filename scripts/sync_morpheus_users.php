<?php

/**
 * Sync Apex workspace users → Morpheus users + extensions via Call Control API.
 *
 * Maps the Morpheus admin UI fields that ARE exposed on the API:
 *   - User: email, first_name, last_name, password, user_level, status, role
 *   - Extension: user_id (assigned softphone ext), password, is_dialer_agent, caller ID
 *
 * User group / "agent" role in the Morpheus portal UI are not on this API — use role=user + is_dialer_agent.
 *
 * Usage: php scripts/sync_morpheus_users.php [--workspace=ApexPayments] [--password=secret]
 */

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\Workspace;
use App\Services\Communications\MorpheusHubService;
use App\Services\Integrations\ZoomApiService;
use App\Support\SalesOps;
use Illuminate\Support\Str;

$workspaceName = 'ApexPayments';
$password = (string) env('MORPHEUS_EXTENSION_PASSWORD', 'apexone_3344');

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--workspace=')) {
        $workspaceName = substr($arg, 12);
    }
    if (str_starts_with($arg, '--password=')) {
        $password = substr($arg, 11);
    }
}

$workspace = Workspace::where('name', $workspaceName)->first();
if (! $workspace) {
    fwrite(STDERR, "Workspace \"{$workspaceName}\" not found.\n");
    exit(1);
}

$api = app(ZoomApiService::class);
if (! $api->isConfigured()) {
    fwrite(STDERR, "Morpheus API not configured.\n");
    exit(1);
}

$hub = app(MorpheusHubService::class);
$extensionsByNum = collect($hub->extensions())->keyBy(fn (array $ext) => (string) ($ext['extension_num'] ?? ''));
$usersById = collect($hub->users())->keyBy('id');

$portalRoles = config('sales_ops.portal_roles', []);
$adminRoles = config('sales_ops.admin_portal_roles', []);

$members = $workspace->users()
    ->wherePivot('status', 'active')
    ->orderBy('name')
    ->get()
    ->sortBy(function (User $member) use ($adminRoles) {
        $role = (string) ($member->pivot->role ?? '');
        if ($role === 'super_admin') {
            return 0;
        }
        if (in_array($role, $adminRoles, true)) {
            return 1;
        }

        return 2;
    })
    ->values();

if ($members->isEmpty()) {
    fwrite(STDERR, "No active workspace members.\n");
    exit(1);
}

// Assign extension slots: admins → 1001 first, then portal agents 1002+
$extensionPool = collect(range(1001, 1020))->values();
$adminExt = '1001';
$portalIndex = 1;

$synced = 0;
$errors = 0;

foreach ($members as $member) {
    $pivot = $member->pivot;
    $role = (string) ($pivot->role ?? '');
    $isAdmin = in_array($role, $adminRoles, true);
    $isPortal = in_array($role, $portalRoles, true);

    if (! $isAdmin && ! $isPortal) {
        continue;
    }

    $targetExt = filled($pivot->morpheus_extension_num)
        ? (string) $pivot->morpheus_extension_num
        : null;

    if (! $targetExt) {
        if ($isAdmin) {
            $targetExt = $adminExt;
        } else {
            $targetExt = (string) ($extensionPool[$portalIndex] ?? '');
            $portalIndex++;
        }
    }

    if ($targetExt === '') {
        echo "Skip {$member->name}: no extension slot available.\n";
        $errors++;
        continue;
    }

    $ext = $extensionsByNum->get($targetExt);
    if (! $ext) {
        echo "Skip {$member->name}: Morpheus extension {$targetExt} not found.\n";
        $errors++;
        continue;
    }

    $extId = (string) ($ext['id'] ?? '');
    $morpheusUserId = (string) ($ext['user_id'] ?? $pivot->morpheus_user_id ?? '');

    if ($isAdmin && $targetExt === $adminExt && filled($ext['user_id'] ?? null)) {
        // Shared admin extension: only the first (highest-priority) admin updates the Morpheus user profile.
        static $adminExtUserSynced = false;
        if ($adminExtUserSynced) {
            $workspace->users()->updateExistingPivot($member->id, [
                'morpheus_user_id' => (string) $ext['user_id'],
                'morpheus_extension_id' => $extId,
                'morpheus_extension_num' => $targetExt,
            ]);
            echo "Linked {$member->name} (".SalesOps::roleLabel($role).") -> ext {$targetExt} (shared admin line)\n";
            $synced++;
            continue;
        }
        $adminExtUserSynced = true;
    }

    if ($morpheusUserId === '') {
        $username = Str::slug(Str::before($member->email, '@'), '_') ?: ('agent'.$member->id);
        $created = $api->createUser([
            'username' => substr($username, 0, 48),
            'password' => $password,
            'email' => $member->email,
            'first_name' => Str::before($member->name, ' ') ?: $member->name,
            'last_name' => Str::contains($member->name, ' ') ? Str::after($member->name, ' ') : '',
            'role' => 'user',
            'status' => 'active',
            'user_level' => 5,
        ]);

        if (isset($created['error']) && ! isset($created['id'])) {
            echo "FAIL create user for {$member->name}: {$created['error']}\n";
            $errors++;
            continue;
        }

        $morpheusUserId = (string) ($created['id'] ?? '');
    }

    $firstName = Str::before($member->name, ' ') ?: $member->name;
    $lastName = Str::contains($member->name, ' ') ? Str::after($member->name, ' ') : '';

    $userPatch = array_filter([
        'email' => $member->email,
        'first_name' => $firstName,
        'last_name' => $lastName,
        'password' => $password,
        'user_level' => 5,
        'status' => 'active',
        'role' => 'user',
    ], fn ($v) => $v !== null && $v !== '');

    $userResult = $api->updateUser($morpheusUserId, $userPatch);
    if (isset($userResult['error']) && ! isset($userResult['id'])) {
        echo "FAIL update user {$member->name}: {$userResult['error']}\n";
        $errors++;
        continue;
    }

    $extPatch = array_filter([
        'user_id' => $morpheusUserId,
        'password' => $password,
        'is_dialer_agent' => true,
        'caller_id_name' => $member->name,
        'outbound_cid_name' => $member->name,
        'status' => 'active',
    ], fn ($v) => $v !== null && $v !== '');

    $extResult = $api->updateExtension($extId, $extPatch);
    if (isset($extResult['error']) && ! isset($extResult['id'])) {
        echo "FAIL update ext {$targetExt} for {$member->name}: {$extResult['error']}\n";
        $errors++;
        continue;
    }

    $workspace->users()->updateExistingPivot($member->id, [
        'morpheus_user_id' => $morpheusUserId,
        'morpheus_extension_id' => $extId,
        'morpheus_extension_num' => $targetExt,
    ]);

    $online = filled($usersById->get($morpheusUserId)['last_login_at'] ?? null) ? 'online' : 'offline';
    echo "Synced {$member->name} (".SalesOps::roleLabel($role).") -> ext {$targetExt}, Morpheus user {$morpheusUserId} [{$online}]\n";
    $synced++;
}

$hub->bustCache();

echo "\nSynced {$synced} user(s)".($errors ? ", {$errors} error(s)" : '').".\n";
echo "SIP password: {$password}\n";
echo "Morpheus portal: https://".config('integrations.morpheus.host')."/\n";
echo "Note: User group \"agents\" must be set in Morpheus portal UI — not exposed on Call Control API.\n";

exit($errors > 0 ? 1 : 0);
