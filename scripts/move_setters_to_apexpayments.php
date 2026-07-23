<?php

/**
 * Move appointment-setter accounts to ApexPayments, then remove ApexOne workspace only.
 * Does not delete user accounts (admin/superadmin) or touch ApexPayments data beyond the listed users.
 */

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$password = '123456';

$apexPayments = Workspace::query()->where('name', 'ApexPayments')->first();
$apexOne = Workspace::query()->where('name', 'ApexOne')->first();

if (! $apexPayments) {
    fwrite(STDERR, "ApexPayments workspace not found.\n");
    exit(1);
}

echo "ApexPayments id={$apexPayments->id}\n";
echo 'ApexOne id='.($apexOne?->id ?? 'none')."\n";

$teamLeadEmail = 'jacob@apexonepayments.com';
$agentEmails = [
    'abdul.qadir@apexonepayments.com',
    'aniba.awan@apexonepayments.com',
    'zaid.ahmad@apexonepayments.com',
    'arshad.ahmad.nawaz@apexonepayments.com',
    'abdullah.baig@apexonepayments.com',
    'muhammad.jibran@apexonepayments.com',
    'muhammad.usman@apexonepayments.com',
    'muhammad.hassan@apexonepayments.com',
];

DB::transaction(function () use ($apexPayments, $apexOne, $teamLeadEmail, $agentEmails, $password) {
    $jacob = User::query()->whereRaw('LOWER(email) = ?', [$teamLeadEmail])->first();
    if (! $jacob) {
        throw new RuntimeException('Jacob not found');
    }

    // Ensure Jacob is Appointment Setter Team Lead on ApexPayments only.
    $jacob->forceFill([
        'password' => $password,
        'password_hint' => $password,
        'current_workspace_id' => $apexPayments->id,
    ])->save();

    if ($apexPayments->users()->where('users.id', $jacob->id)->exists()) {
        $apexPayments->users()->updateExistingPivot($jacob->id, [
            'role' => 'appointment_setter_team_lead',
            'status' => 'active',
            'team_lead_user_id' => null,
            'joined_at' => now(),
        ]);
    } else {
        $apexPayments->users()->attach($jacob->id, [
            'role' => 'appointment_setter_team_lead',
            'status' => 'active',
            'joined_at' => now(),
            'team_lead_user_id' => null,
            'campaign_id' => null,
            'module_permissions' => null,
        ]);
    }
    echo "jacob -> ApexPayments TL\n";

    foreach ($agentEmails as $email) {
        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        if (! $user) {
            echo "MISSING agent {$email}\n";
            continue;
        }

        $user->forceFill([
            'password' => $password,
            'password_hint' => $password,
            'current_workspace_id' => $apexPayments->id,
        ])->save();

        if ($apexPayments->users()->where('users.id', $user->id)->exists()) {
            $apexPayments->users()->updateExistingPivot($user->id, [
                'role' => 'appointment_setter',
                'status' => 'active',
                'team_lead_user_id' => $jacob->id,
                'joined_at' => now(),
            ]);
        } else {
            $apexPayments->users()->attach($user->id, [
                'role' => 'appointment_setter',
                'status' => 'active',
                'joined_at' => now(),
                'team_lead_user_id' => $jacob->id,
                'campaign_id' => null,
                'module_permissions' => null,
            ]);
        }
        echo "agent -> ApexPayments: {$user->name}\n";
    }

    if ($apexOne) {
        // Detach only the moved accounts from ApexOne (keep admin rows until workspace delete).
        $moveEmails = array_map('strtolower', array_merge([$teamLeadEmail], $agentEmails));
        $detachIds = User::query()
            ->where(function ($q) use ($moveEmails) {
                foreach ($moveEmails as $email) {
                    $q->orWhereRaw('LOWER(email) = ?', [$email]);
                }
            })
            ->pluck('id')
            ->all();

        if ($detachIds !== []) {
            DB::table('workspace_user')
                ->where('workspace_id', $apexOne->id)
                ->whereIn('user_id', $detachIds)
                ->delete();
            echo 'detached from ApexOne count='.count($detachIds)."\n";
        }

        // Point any remaining users still on ApexOne current_workspace away if needed.
        User::query()
            ->where('current_workspace_id', $apexOne->id)
            ->update(['current_workspace_id' => $apexPayments->id]);

        // Remove memberships, then delete ApexOne workspace only.
        DB::table('workspace_user')->where('workspace_id', $apexOne->id)->delete();

        // Clear lightweight FK children if present (ApexOne had 0 pipelines).
        foreach ([
            'workflows',
            'workflow_leads',
            'lead_campaigns',
            'workspace_invitations',
            'workspace_sync_events',
            'maps_scrape_jobs',
            'deliverability_tests',
        ] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'workspace_id')) {
                DB::table($table)->where('workspace_id', $apexOne->id)->delete();
            }
        }

        $apexOne->delete();
        echo "DELETED workspace ApexOne\n";
    }
});

// Verify
$apexPayments = Workspace::query()->where('name', 'ApexPayments')->firstOrFail();
$apexOneGone = Workspace::query()->where('name', 'ApexOne')->doesntExist();
echo 'VERIFY apexone_gone='.($apexOneGone ? 'yes' : 'no')."\n";
echo 'VERIFY workspaces='.Workspace::query()->orderBy('id')->pluck('name')->implode(', ')."\n";

$jacob = User::query()->whereRaw('LOWER(email) = ?', ['jacob@apexonepayments.com'])->firstOrFail();
$jp = $apexPayments->users()->where('users.id', $jacob->id)->first();
echo 'VERIFY jacob role='.($jp?->pivot?->role).' tl='.($jp?->pivot?->team_lead_user_id ?: 'null').' login='.(password_verify('123456', (string) $jacob->getAuthPassword()) ? 'yes' : 'no')."\n";

$team = $apexPayments->users()
    ->wherePivot('role', 'appointment_setter')
    ->wherePivot('team_lead_user_id', $jacob->id)
    ->wherePivot('status', 'active')
    ->orderBy('users.name')
    ->get();

echo 'VERIFY agents_under_jacob='.$team->count()."\n";
foreach ($team as $m) {
    echo "  - {$m->name} <{$m->email}>\n";
}

// Ensure admin users still exist
foreach (['admin@apexonepayment.com', 'superadmin@apexonepayment.com'] as $email) {
    $u = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
    echo 'VERIFY keep_user '.($u ? $u->email : "MISSING {$email}")."\n";
}

echo "DONE\n";
