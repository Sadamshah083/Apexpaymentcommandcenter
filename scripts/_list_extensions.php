#!/usr/bin/env php
<?php

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$ws = App\Models\Workspace::where('name', 'ApexPayments')->firstOrFail();
$users = $ws->users()->orderBy('name')->get(['users.id', 'users.name', 'users.email']);

foreach ($users as $u) {
    $p = $u->pivot;
    echo sprintf(
        "%s | role=%s | ext=%s | ext_id=%s | status=%s\n",
        $u->name,
        $p->role,
        $p->morpheus_extension_num ?: '-',
        $p->morpheus_extension_id ?: '-',
        $p->status
    );
}
