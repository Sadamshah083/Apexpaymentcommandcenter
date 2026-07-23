<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Schema;

echo Schema::hasColumn('users', 'password_hint') ? "HAS_HINT\n" : "NO_HINT\n";

$filled = User::query()->whereNotNull('password_hint')->where('password_hint', '!=', '')->count();
$empty = User::query()->where(function ($q) {
    $q->whereNull('password_hint')->orWhere('password_hint', '');
})->count();
echo "filled={$filled} empty={$empty}\n";

$sample = User::query()
    ->whereNotNull('password_hint')
    ->where('password_hint', '!=', '')
    ->latest('id')
    ->first(['id', 'email', 'password_hint']);

if ($sample) {
    echo "sample id={$sample->id} hint={$sample->password_hint}\n";
}
