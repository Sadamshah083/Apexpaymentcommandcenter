<?php

namespace Database\Seeders;

use App\Models\FreeProviderDomain;
use Illuminate\Database\Seeder;

class FreeProviderDomainSeeder extends Seeder
{
    public function run(): void
    {
        $domains = [
            'gmail.com', 'googlemail.com', 'yahoo.com', 'yahoo.co.uk', 'yahoo.fr',
            'hotmail.com', 'outlook.com', 'live.com', 'msn.com', 'aol.com',
            'icloud.com', 'me.com', 'mac.com', 'protonmail.com', 'proton.me',
            'zoho.com', 'yandex.com', 'yandex.ru', 'mail.ru', 'gmx.com', 'gmx.net',
            'web.de', 'tutanota.com', 'fastmail.com', 'hey.com', 'mail.com',
            'inbox.com', 'rediffmail.com', 'qq.com', '163.com', '126.com',
        ];

        foreach ($domains as $domain) {
            FreeProviderDomain::firstOrCreate(['domain' => $domain]);
        }
    }
}
