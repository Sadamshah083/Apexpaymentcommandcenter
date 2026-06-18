<?php

namespace App\Console\Commands;

use App\Jobs\SyncDisposableDomainsJob;
use Illuminate\Console\Command;

class SyncDisposableDomainsCommand extends Command
{
    protected $signature = 'email-checker:sync-disposable-domains {--sync : Run synchronously instead of queueing}';

    protected $description = 'Sync disposable email domain blocklist from GitHub';

    public function handle(): int
    {
        $this->info('Syncing disposable email domains...');

        if ($this->option('sync')) {
            (new SyncDisposableDomainsJob)->handle();
        } else {
            SyncDisposableDomainsJob::dispatch();
        }

        $this->info('Disposable domain sync '.($this->option('sync') ? 'completed' : 'queued').'.');

        return self::SUCCESS;
    }
}
