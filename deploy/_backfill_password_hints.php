<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Hash;

$known = [
    'admin@apexonepayment.com' => '123456',
    'admin@apexonepayments.com' => '123456',
    'superadmin@apexonepayment.com' => '123456',
    'superadmin@apexonepayments.com' => '123456',
    'setter_tl_48c@apexpayments.com' => 'H3@aB6(uK9)mN2!w',
    'closer_tl_53d@apexpayments.com' => 'J4#sC7*vL1%qP4&y',
    'setter_k8z@apexpayments.com' => 'D9$rF3!mX7*pV2%q',
    'setter_p4w@apexpayments.com' => 'C3#nK7^tW9&yB1%u',
    'setter_m5r@apexpayments.com' => 'G6@aL2(uP9)mN4!v',
    'setter_v9t@apexpayments.com' => 'F4#sD8*xM1%qJ7&w',
    'closer_f7x@apexpayments.com' => 'S9&vK3!mP7*rT2%q',
    'closer_w4y@apexpayments.com' => 'Z3#nJ7^tW9&uC1%v',
    'closer_g6z@apexpayments.com' => 'X6@aM2(uR9)mP4!w',
    'closer_q8v@apexpayments.com' => 'Y4#sB8*zN1%qK7&x',
];

$missing = User::query()
    ->where(function ($q) {
        $q->whereNull('password_hint')->orWhere('password_hint', '');
    })
    ->get(['id', 'name', 'email', 'password']);

foreach ($missing as $user) {
    $email = strtolower((string) $user->email);
    $candidate = $known[$email] ?? null;

    // Try common seed passwords if email match unknown.
    $candidates = array_values(array_unique(array_filter([
        $candidate,
        '123456',
        'balitech@001',
        'balitech@007',
    ])));

    $filled = null;
    foreach ($candidates as $plain) {
        if (Hash::check($plain, $user->password)) {
            $filled = $plain;
            break;
        }
    }

    if ($filled !== null) {
        $user->update(['password_hint' => $filled]);
        echo "filled\t{$user->id}\t{$user->email}\n";
    } else {
        echo "missing\t{$user->id}\t{$user->name}\t{$user->email}\n";
    }
}

$left = User::query()
    ->where(function ($q) {
        $q->whereNull('password_hint')->orWhere('password_hint', '');
    })
    ->count();
echo "remaining_missing={$left}\n";
