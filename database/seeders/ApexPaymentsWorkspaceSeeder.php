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
            ['email' => 'superadmin_secure_928@apexpayments.com'],
            ['name' => 'apex_superadmin_secure_928', 'password' => Hash::make('SuperSecureAdminPass928!')]
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
            ['username' => 'apex_admin_secure_417', 'email' => 'admin_secure_417@apexpayments.com', 'role' => 'admin', 'password' => 'AdminSecurePass417!'],
            ['username' => 'setter_tl_secure_529', 'email' => 'settertl_secure_529@apexpayments.com', 'role' => 'appointment_setter_team_lead', 'password' => 'SetterTLSecurePass529!'],
            ['username' => 'closer_tl_secure_641', 'email' => 'closertl_secure_641@apexpayments.com', 'role' => 'closers_team_lead', 'password' => 'CloserTLSecurePass641!'],
        ];

        $passwords = [
            'appointment_setter' => [
                1 => 'Setter1SecurePass803!',
                2 => 'Setter2SecurePass804!',
                3 => 'Setter3SecurePass805!',
                4 => 'Setter4SecurePass806!'
            ],
            'closer' => [
                1 => 'Closer1SecurePass390!',
                2 => 'Closer2SecurePass391!',
                3 => 'Closer3SecurePass392!',
                4 => 'Closer4SecurePass393!'
            ]
        ];

        foreach (range(1, 4) as $i) {
            $accounts[] = [
                'username' => "setter_agent_{$i}_secure_80" . ($i + 2),
                'email' => "setter{$i}_secure_80" . ($i + 2) . "@apexpayments.com",
                'role' => 'appointment_setter',
                'password' => $passwords['appointment_setter'][$i]
            ];
            $accounts[] = [
                'username' => "closer_agent_{$i}_secure_39" . ($i - 1),
                'email' => "closer{$i}_secure_39" . ($i - 1) . "@apexpayments.com",
                'role' => 'closer',
                'password' => $passwords['closer'][$i]
            ];
        }

        foreach ($accounts as $account) {
            $user = User::firstOrCreate(
                ['email' => $account['email']],
                ['name' => $account['username'], 'password' => Hash::make($account['password'])]
            );
            $user->update(['current_workspace_id' => $workspace->id]);
            $workspace->users()->syncWithoutDetaching([
                $user->id => ['role' => $account['role'], 'status' => 'active', 'joined_at' => now()],
            ]);
        }
    }
}
