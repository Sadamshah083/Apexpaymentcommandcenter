<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$admin = App\Models\User::where('email', 'admin@apexonepayment.com')->first();
$super = App\Models\User::where('email', 'superadmin@apexonepayment.com')->first();
$ws = App\Models\Workspace::find(2);
echo "admin_id=".($admin?->id)." role=".$admin->getWorkspaceRole(2)." can_manage=".($admin->canManageWorkspaceMembers(2)?'yes':'no')."\n";
echo "super_id=".($super?->id)." role=".$super->getWorkspaceRole(2)." can_manage=".($super->canManageWorkspaceMembers(2)?'yes':'no')."\n";
echo "ws={$ws->id} {$ws->name}\n";
