<?php

namespace Database\Seeders;

use App\Models\RolePrefix;
use Illuminate\Database\Seeder;

class RolePrefixSeeder extends Seeder
{
    public function run(): void
    {
        $prefixes = [
            'admin', 'administrator', 'info', 'support', 'help', 'sales', 'marketing',
            'contact', 'noreply', 'no-reply', 'donotreply', 'do-not-reply', 'postmaster',
            'webmaster', 'hostmaster', 'abuse', 'security', 'billing', 'accounts',
            'hr', 'jobs', 'careers', 'press', 'media', 'news', 'team', 'office',
            'hello', 'feedback', 'service', 'customerservice', 'enquiries', 'inquiries',
        ];

        foreach ($prefixes as $prefix) {
            RolePrefix::firstOrCreate(['prefix' => $prefix]);
        }
    }
}
