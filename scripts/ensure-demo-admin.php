<?php

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Hash;

$username = 'admin';
$password = 'password123';

$user = User::where('name', $username)->first();

if (! $user) {
    $user = User::create([
        'name' => $username,
        'email' => 'admin@apexone.local',
        'password' => Hash::make($password),
    ]);
    echo "Created admin user.\n";
} else {
    $user->update(['password' => Hash::make($password)]);
    echo "Reset password for existing admin user.\n";
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
    echo "Created workspace.\n";
} elseif (! $user->current_workspace_id) {
    $workspace = $user->workspaces()->first();
    $user->update(['current_workspace_id' => $workspace->id]);
}

echo "Username: {$username}\n";
echo "Password: {$password}\n";
echo "Admin login: /admin/login\n";
