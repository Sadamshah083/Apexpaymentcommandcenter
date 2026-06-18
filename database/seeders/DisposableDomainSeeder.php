<?php

namespace Database\Seeders;

use App\Models\DisposableDomain;
use Illuminate\Database\Seeder;

class DisposableDomainSeeder extends Seeder
{
    public function run(): void
    {
        $common = [
            'mailinator.com', 'guerrillamail.com', 'guerrillamail.net', 'sharklasers.com',
            'yopmail.com', 'tempmail.com', '10minutemail.com', 'trashmail.com',
            'getnada.com', 'maildrop.cc', 'dispostable.com', 'fakeinbox.com',
            'throwaway.email', 'temp-mail.org', 'emailondeck.com', 'mintemail.com',
        ];

        foreach ($common as $domain) {
            DisposableDomain::firstOrCreate(['domain' => $domain], ['synced_at' => now()]);
        }
    }
}
