<?php

use App\Jobs\PollInboundMailboxJob;
use App\Jobs\SyncDisposableDomainsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new SyncDisposableDomainsJob)->weekly();
Schedule::job(new PollInboundMailboxJob)->everyFiveMinutes();
