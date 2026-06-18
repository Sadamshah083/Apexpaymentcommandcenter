<?php

namespace Database\Seeders;

use App\Models\SpamRule;
use Illuminate\Database\Seeder;

class SpamRuleSeeder extends Seeder
{
    public function run(): void
    {
        $rules = require database_path('data/spam_rules.php');

        foreach ($rules as $rule) {
            SpamRule::updateOrCreate(
                ['name' => $rule['name']],
                array_merge($rule, ['match_type' => 'regex', 'is_active' => true])
            );
        }
    }
}
