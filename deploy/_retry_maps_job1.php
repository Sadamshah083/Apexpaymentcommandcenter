<?php

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo 'ENABLED='.var_export(config('maps_scraper.enabled'), true).PHP_EOL;
echo 'PYTHON='.config('maps_scraper.python').PHP_EOL;

try {
    app(App\Services\MapsScraper\MapsScraperService::class)->assertReady();
    echo "ASSERT_OK\n";
} catch (Throwable $e) {
    echo 'ASSERT_FAIL='.$e->getMessage().PHP_EOL;
    exit(1);
}

$job = App\Models\MapsScrapeJob::query()->find(1);
if (! $job) {
    echo "NO_JOB\n";
    exit(0);
}

$job->update([
    'status' => 'pending',
    'progress_pct' => 0,
    'progress_message' => 'Ready — start a new scrape or re-queue from UI',
    'error_message' => null,
]);

echo 'JOB1_STATUS='.$job->status.PHP_EOL;
echo "READY\n";
