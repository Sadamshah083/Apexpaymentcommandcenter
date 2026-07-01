<?php

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Hash;

$username = env('PRODUCTION_ADMIN_USER', 'admin');
$email = env('PRODUCTION_ADMIN_EMAIL', 'admin@apexone.local');
$password = env('PRODUCTION_ADMIN_PASSWORD') ?: getenv('PRODUCTION_ADMIN_PASSWORD');

if (! $password && file_exists(__DIR__.'/../.env')) {
    $lines = file(__DIR__.'/../.env');
    foreach ($lines as $line) {
        if (strpos(trim($line), 'PRODUCTION_ADMIN_PASSWORD=') === 0) {
            $password = trim(substr(trim($line), strlen('PRODUCTION_ADMIN_PASSWORD=')), '"\'');
            break;
        }
    }
}

if (! $password) {
    fwrite(STDERR, "PRODUCTION_ADMIN_PASSWORD is required.\n");
    exit(1);
}

$user = User::where('name', $username)->first();

if (! $user) {
    $user = User::create([
        'name' => $username,
        'email' => $email,
        'password' => Hash::make($password),
    ]);
    echo "Created admin user.\n";
} else {
    $user->update([
        'email' => $email,
        'password' => Hash::make($password),
    ]);
    echo "Updated admin user.\n";
}

if (! $user->workspaces()->exists()) {
    $workspace = Workspace::create([
        'name' => 'ApexOne',
        'admin_id' => $user->id,
    ]);
    $workspace->users()->attach($user->id, [
        'role' => 'admin',
        'status' => 'active',
        'joined_at' => now(),
    ]);
    $user->update(['current_workspace_id' => $workspace->id]);
    echo "Created default workspace.\n";
} elseif (! $user->current_workspace_id) {
    $user->update(['current_workspace_id' => $user->workspaces()->first()?->id]);
}

echo "Admin portal: /admin/login\n";
echo "Username: {$username}\n";
