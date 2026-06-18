<?php

namespace App\Console\Commands;

use App\Services\Communications\CommunicationsDataService;
use App\Services\Integrations\ZoomApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class ClearZoomTokenCommand extends Command
{
    protected $signature = 'zoom:clear-token {--cache : Also clear communications hub API cache}';

    protected $description = 'Clear the cached Zoom Server-to-Server OAuth access token';

    public function handle(ZoomApiService $zoom, CommunicationsDataService $communications): int
    {
        $zoom->clearAccessTokenCache();
        $communications->bustCache();

        if ($this->option('cache')) {
            Artisan::call('cache:clear');
            $this->info('Application cache cleared.');
        }

        $this->info('Zoom access token cache cleared. The next API request will request a new token.');
        $this->line('Tip: run with --cache after adding new Zoom scopes, or: php artisan cache:clear');

        return self::SUCCESS;
    }
}
