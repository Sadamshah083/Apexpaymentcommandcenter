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
        // Prefer migrating the legacy super-admin row when present.
        $legacySuper = User::query()
            ->where(function ($q) {
                $q->where('email', 'admin_super_91a@apexpayments.com')
                    ->orWhere('name', 'admin_super_91a');
            })
            ->first();

        if ($legacySuper) {
            $legacySuper->forceFill([
                'email' => 'superadmin@apexonepayment.com',
                'name' => 'superadmin',
                'password' => Hash::make('123456'),
            ])->save();
            $superAdmin = $legacySuper;
        } else {
            $superAdmin = User::updateOrCreate(
                ['email' => 'superadmin@apexonepayment.com'],
                ['name' => 'superadmin', 'password' => Hash::make('123456')]
            );
        }

        $workspace = Workspace::firstOrCreate(
            ['name' => 'ApexPayments'],
            ['admin_id' => $superAdmin->id]
        );

        $superAdmin->update(['current_workspace_id' => $workspace->id]);
        $workspace->users()->syncWithoutDetaching([
            $superAdmin->id => ['role' => 'super_admin', 'status' => 'active', 'joined_at' => now()],
        ]);

        $accounts = [
            [
                'username' => 'admin',
                'email' => 'admin@apexonepayment.com',
                'role' => 'admin',
                'password' => '123456',
                'legacy_emails' => ['admin_ops_74b@apexpayments.com'],
                'legacy_names' => ['admin_ops_74b'],
            ],
            [
                'username' => 'setter_tl_48c',
                'email' => 'setter_tl_48c@apexpayments.com',
                'role' => 'appointment_setter_team_lead',
                'password' => 'H3@aB6(uK9)mN2!w'
            ],
            [
                'username' => 'closer_tl_53d',
                'email' => 'closer_tl_53d@apexpayments.com',
                'role' => 'closers_team_lead',
                'password' => 'J4#sC7*vL1%qP4&y'
            ],
            [
                'username' => 'setter_ag_k8z',
                'email' => 'setter_k8z@apexpayments.com',
                'role' => 'appointment_setter',
                'password' => 'D9$rF3!mX7*pV2%q'
            ],
            [
                'username' => 'setter_ag_p4w',
                'email' => 'setter_p4w@apexpayments.com',
                'role' => 'appointment_setter',
                'password' => 'C3#nK7^tW9&yB1%u'
            ],
            [
                'username' => 'setter_ag_m5r',
                'email' => 'setter_m5r@apexpayments.com',
                'role' => 'appointment_setter',
                'password' => 'G6@aL2(uP9)mN4!v'
            ],
            [
                'username' => 'setter_ag_v9t',
                'email' => 'setter_v9t@apexpayments.com',
                'role' => 'appointment_setter',
                'password' => 'F4#sD8*xM1%qJ7&w'
            ],
            [
                'username' => 'closer_ag_f7x',
                'email' => 'closer_f7x@apexpayments.com',
                'role' => 'closer',
                'password' => 'S9&vK3!mP7*rT2%q'
            ],
            [
                'username' => 'closer_ag_w4y',
                'email' => 'closer_w4y@apexpayments.com',
                'role' => 'closer',
                'password' => 'Z3#nJ7^tW9&uC1%v'
            ],
            [
                'username' => 'closer_ag_g6z',
                'email' => 'closer_g6z@apexpayments.com',
                'role' => 'closer',
                'password' => 'X6@aM2(uR9)mP4!w'
            ],
            [
                'username' => 'closer_ag_q8v',
                'email' => 'closer_q8v@apexpayments.com',
                'role' => 'closer',
                'password' => 'Y4#sB8*zN1%qK7&x'
            ],
        ];

        foreach ($accounts as $account) {
            $legacyEmails = $account['legacy_emails'] ?? [];
            $legacyNames = $account['legacy_names'] ?? [];
            if ($legacyEmails !== [] || $legacyNames !== []) {
                User::query()
                    ->where(function ($q) use ($legacyEmails, $legacyNames, $account) {
                        if ($legacyEmails !== []) {
                            $q->orWhereIn('email', $legacyEmails);
                        }
                        if ($legacyNames !== []) {
                            $q->orWhereIn('name', $legacyNames);
                        }
                        $q->orWhere('email', $account['email']);
                    })
                    ->update([
                        'email' => $account['email'],
                        'name' => $account['username'],
                        'password' => Hash::make($account['password']),
                    ]);
            }

            $user = User::updateOrCreate(
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
