<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$ws = App\Models\Workspace::find(2);
foreach ($ws->users()->orderBy('users.name')->get() as $u) {
    echo $u->id."\t".$u->name."\t".$u->pivot->role."\t".($u->pivot->status)."\tlead=".($u->pivot->team_lead_user_id?:'-')."\n";
}
