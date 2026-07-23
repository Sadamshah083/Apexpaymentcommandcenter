<?php

require dirname(__DIR__) . '/vendor/autoload.php';
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Hash;

$updated = 0;
$emailFixed = 0;
$hintOnly = 0;

foreach (User::query()->orderBy('id')->cursor() as $user) {
    $dirty = false;

    $needsPassword = ! Hash::check('123456', (string) $user->password);
    $needsHint = (string) ($user->password_hint ?? '') !== '123456';
    if ($needsPassword || $needsHint) {
        if ($needsPassword) {
            $user->password = '123456';
            $updated++;
        } else {
            $hintOnly++;
        }
        $user->password_hint = '123456';
        $dirty = true;
    }

    $email = strtolower((string) $user->email);
    if (str_ends_with($email, '@apexpayments.com')) {
        $local = strstr($email, '@', true) ?: preg_replace('/\s+/', '', strtolower((string) $user->name));
        $local = preg_replace('/[^a-z0-9._+-]/', '', (string) $local) ?: ('agent'.$user->id);
        $candidate = $local.'@apexonepayments.com';
        if (! User::query()->where('email', $candidate)->where('id', '!=', $user->id)->exists()) {
            $user->email = $candidate;
            $emailFixed++;
            $dirty = true;
        }
    }

    if ($dirty) {
        $user->save();
    }
}

echo "passwords_reset={$updated} hints_synced={$hintOnly} emails_fixed={$emailFixed}\n";
$sample = User::query()->orderBy('id')->limit(10)->get(['id', 'name', 'email', 'password_hint']);
foreach ($sample as $u) {
    echo $u->id.'|'.$u->name.'|'.$u->email.'|'.($u->password_hint ?: '-')."\n";
}
