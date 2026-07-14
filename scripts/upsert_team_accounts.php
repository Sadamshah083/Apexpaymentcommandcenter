#!/usr/bin/env php
<?php

/**
 * Upsert TeamLead / Agent / QA accounts into ApexPayments and set passwords to 123456.
 * Also resets Admin + Super Admin passwords to 123456 (roles unchanged).
 *
 * Usage: php scripts/upsert_team_accounts.php
 */

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$password = '123456';
$workspace = Workspace::where('name', 'ApexPayments')->first();

if (! $workspace) {
    fwrite(STDERR, "Workspace ApexPayments not found.\n");
    exit(1);
}

$accounts = [
    ['name' => 'ElijahMorgan', 'email' => 'elijahmorgan@apexonepayments.com', 'role' => 'appointment_setter_team_lead'],
    ['name' => 'JamesHenderson', 'email' => 'jameshenderson@apexonepayments.com', 'role' => 'appointment_setter'],
    ['name' => 'tonnynewman', 'email' => 'tonnynewman@apexonepayments.com', 'role' => 'appointment_setter'],
    ['name' => 'damonpeterson', 'email' => 'damonpeterson@apexonepayments.com', 'role' => 'appointment_setter_team_lead'],
    ['name' => 'Jacob Khan', 'email' => 'jacob@apexonepayments.com', 'role' => 'appointment_setter'],
    ['name' => 'Nina Jones', 'email' => 'nina@apexonepayments.com', 'role' => 'appointment_setter'],
    ['name' => 'Hannah', 'email' => 'hannah@apexonepayments.com', 'role' => 'manager'], // QA → Manager (closest role)
    ['name' => 'SophiaHeather', 'email' => 'sophia@apexonepayments.com', 'role' => 'appointment_setter', 'password' => 'Amisa123'],
];

$created = 0;
$updated = 0;

foreach ($accounts as $account) {
    $user = User::query()
        ->where(function ($query) use ($account) {
            $query->whereRaw('LOWER(email) = ?', [strtolower($account['email'])])
                ->orWhere('name', $account['name']);
        })
        ->first();

    if ($user) {
        $user->forceFill([
            'name' => $account['name'],
            'email' => strtolower($account['email']),
            'password' => $account['password'] ?? $password,
            'current_workspace_id' => $workspace->id,
        ])->save();
        $updated++;
        $action = 'updated';
    } else {
        $user = User::create([
            'name' => $account['name'],
            'email' => strtolower($account['email']),
            'password' => $account['password'] ?? $password,
            'current_workspace_id' => $workspace->id,
        ]);
        $created++;
        $action = 'created';
    }

    $workspace->users()->syncWithoutDetaching([
        $user->id => [
            'role' => $account['role'],
            'status' => 'active',
            'joined_at' => now(),
        ],
    ]);

    echo "{$action}: {$account['name']} <{$account['email']}> role={$account['role']}\n";
}

// Reset Admin + Super Admin passwords only (keep roles).
$adminUsers = $workspace->users()
    ->wherePivotIn('role', ['super_admin', 'admin'])
    ->get();

if ($workspace->admin_id) {
    $owner = User::find($workspace->admin_id);
    if ($owner && ! $adminUsers->contains('id', $owner->id)) {
        $adminUsers->push($owner);
    }
}

foreach ($adminUsers as $adminUser) {
    $adminUser->forceFill(['password' => $password])->save();
    $role = $adminUser->getWorkspaceRole($workspace->id) ?? 'owner';
    echo "password reset: {$adminUser->name} <{$adminUser->email}> role={$role}\n";
}

echo "Done. created={$created} updated={$updated} admin_passwords=".count($adminUsers)."\n";
