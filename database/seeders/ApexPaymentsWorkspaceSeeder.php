<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class ApexPaymentsWorkspaceSeeder extends Seeder
{
    public function run(): void
    {
        $superAdmin = User::firstOrCreate(
            ['email' => 'superadmin@apexpayments.local'],
            ['name' => 'apex_super', 'password' => Hash::make('password')]
        );

        $workspace = Workspace::firstOrCreate(
            ['name' => 'ApexPayments'],
            ['admin_id' => $superAdmin->id]
        );

        $superAdmin->update(['current_workspace_id' => $workspace->id]);
        $workspace->users()->syncWithoutDetaching([
            $superAdmin->id => ['role' => 'super_admin', 'status' => 'active', 'joined_at' => now()],
        ]);

        $accounts = [
            ['username' => 'apex_admin', 'email' => 'admin@apexpayments.local', 'role' => 'admin'],
            ['username' => 'setter_tl', 'email' => 'settertl@apexpayments.local', 'role' => 'appointment_setter_team_lead'],
            ['username' => 'closer_tl', 'email' => 'closertl@apexpayments.local', 'role' => 'closers_team_lead'],
        ];

        foreach (range(1, 4) as $i) {
            $accounts[] = ['username' => "setter{$i}", 'email' => "setter{$i}@apexpayments.local", 'role' => 'appointment_setter'];
            $accounts[] = ['username' => "closer{$i}", 'email' => "closer{$i}@apexpayments.local", 'role' => 'closer'];
        }

        foreach ($accounts as $account) {
            $user = User::firstOrCreate(
                ['email' => $account['email']],
                ['name' => $account['username'], 'password' => Hash::make('password')]
            );
            $user->update(['current_workspace_id' => $workspace->id]);
            $workspace->users()->syncWithoutDetaching([
                $user->id => ['role' => $account['role'], 'status' => 'active', 'joined_at' => now()],
            ]);
        }
    }
}
