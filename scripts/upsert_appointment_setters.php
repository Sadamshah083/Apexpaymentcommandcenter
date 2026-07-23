<?php

/**
 * Rename-aware upsert: Jacob = Appointment Setter Team Lead,
 * listed agents = B2B Appointment Setters under Jacob.
 * Password for all: 123456 (stored hashed + password_hint plaintext).
 */

use App\Models\User;
use App\Models\Workspace;
use App\Support\SalesOps;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$password = '123456';

$workspace = Workspace::query()
    ->where('name', 'ApexPayments')
    ->first();

if (! $workspace) {
    fwrite(STDERR, "Workspace ApexPayments not found.\n");
    exit(1);
}

echo "Workspace id={$workspace->id} name={$workspace->name}\n";
echo 'Role labels: setter='.SalesOps::roleLabel('appointment_setter')
    .' | tl='.SalesOps::roleLabel('appointment_setter_team_lead')."\n";

$teamLead = [
    'name' => 'Jacob Khan',
    'email' => 'jacob@apexonepayments.com',
    'role' => 'appointment_setter_team_lead',
];

$agents = [
    ['name' => 'Abdul Qadir', 'email' => 'abdul.qadir@apexonepayments.com'],
    ['name' => 'Aniba Awan', 'email' => 'aniba.awan@apexonepayments.com'],
    ['name' => 'Zaid Ahmad', 'email' => 'zaid.ahmad@apexonepayments.com'],
    ['name' => 'Arshad Ahmad Nawaz', 'email' => 'arshad.ahmad.nawaz@apexonepayments.com'],
    ['name' => 'Abdullah Baig', 'email' => 'abdullah.baig@apexonepayments.com'],
    ['name' => 'Muhammad Jibran', 'email' => 'muhammad.jibran@apexonepayments.com'],
    ['name' => 'Muhammad Usman', 'email' => 'muhammad.usman@apexonepayments.com'],
    ['name' => 'Muhammad Hassan', 'email' => 'muhammad.hassan@apexonepayments.com'],
];

/**
 * @param  array{name:string,email:string,role?:string}  $account
 */
function upsertPortalMember(Workspace $workspace, array $account, string $password, ?int $teamLeadUserId = null): User
{
    $email = strtolower(trim($account['email']));
    $name = trim($account['name']);
    $role = $account['role'] ?? 'appointment_setter';

    $user = User::query()
        ->whereRaw('LOWER(email) = ?', [$email])
        ->first();

    if (! $user) {
        // Recover by exact name match only when email is free / unused.
        $byName = User::query()->where('name', $name)->first();
        if ($byName && strcasecmp((string) $byName->email, $email) !== 0) {
            // Prefer email identity; keep separate if name collision with different email.
            $user = null;
        } else {
            $user = $byName;
        }
    }

    if ($user) {
        $user->forceFill([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'password_hint' => $password,
            'current_workspace_id' => $workspace->id,
        ])->save();
        $action = 'updated';
    } else {
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'password_hint' => $password,
            'current_workspace_id' => $workspace->id,
        ]);
        $action = 'created';
    }

    $pivot = [
        'role' => $role,
        'status' => 'active',
        'joined_at' => now(),
        'team_lead_user_id' => SalesOps::isTeamLeadRole($role) ? null : $teamLeadUserId,
    ];

    if ($workspace->users()->where('users.id', $user->id)->exists()) {
        $workspace->users()->updateExistingPivot($user->id, $pivot);
    } else {
        $workspace->users()->attach($user->id, array_merge($pivot, [
            'module_permissions' => null,
            'campaign_id' => null,
        ]));
    }

    echo "{$action}: {$name} <{$email}> role={$role} tl=".($pivot['team_lead_user_id'] ?: 'null')."\n";

    return $user->fresh();
}

DB::transaction(function () use ($workspace, $teamLead, $agents, $password) {
    $jacob = upsertPortalMember($workspace, $teamLead, $password, null);

    foreach ($agents as $agent) {
        upsertPortalMember($workspace, array_merge($agent, [
            'role' => 'appointment_setter',
        ]), $password, (int) $jacob->id);
    }

    // Ensure any existing Jacob pivot is TL (no self team lead).
    $workspace->users()->updateExistingPivot($jacob->id, [
        'role' => 'appointment_setter_team_lead',
        'status' => 'active',
        'team_lead_user_id' => null,
    ]);
});

// Verify
$jacob = User::query()->whereRaw('LOWER(email) = ?', ['jacob@apexonepayments.com'])->firstOrFail();
$jacobPivot = $workspace->users()->where('users.id', $jacob->id)->first();
echo 'VERIFY jacob role='.($jacobPivot?->pivot?->role)."\n";
echo 'VERIFY jacob password_hint='.($jacob->password_hint ?? '')."\n";
echo 'VERIFY jacob login_ok='.(password_verify('123456', (string) $jacob->password) ? 'yes' : 'no')."\n";

$team = $workspace->users()
    ->wherePivot('role', 'appointment_setter')
    ->wherePivot('team_lead_user_id', $jacob->id)
    ->wherePivot('status', 'active')
    ->orderBy('users.name')
    ->get(['users.id', 'users.name', 'users.email', 'users.password_hint']);

echo 'VERIFY agents_under_jacob='.$team->count()."\n";
foreach ($team as $member) {
    $ok = password_verify('123456', (string) $member->password) ? 'yes' : 'no';
    echo "  - {$member->name} <{$member->email}> hint=".($member->password_hint ?? '')." login_ok={$ok}\n";
}

$labelSetter = SalesOps::roleLabel('appointment_setter');
$labelTl = SalesOps::roleLabel('appointment_setter_team_lead');
if (! str_contains(strtolower($labelSetter), 'appointment')) {
    fwrite(STDERR, "Label rename missing: {$labelSetter}\n");
    exit(1);
}

echo "DONE labels=[{$labelSetter}] / [{$labelTl}]\n";
