<?php

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

Artisan::call('db:seed', [
    '--class' => 'Database\\Seeders\\ApexPaymentsWorkspaceSeeder',
    '--force' => true,
]);

echo Artisan::output();

$adminPassword = env('PRODUCTION_ADMIN_PASSWORD', 'rwlt4NBN2MtIbQ0A');
$adminUser = User::where('name', env('PRODUCTION_ADMIN_USER', 'admin'))->first();

if ($adminUser) {
    $adminUser->update(['password' => Hash::make($adminPassword)]);
    echo "Updated system admin ({$adminUser->name}) password.\n";
} else {
    echo "System admin user not found — run scripts/production-bootstrap.php first.\n";
}

echo "ApexPayments workspace credentials restored.\n";
