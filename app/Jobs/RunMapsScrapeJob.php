<?php

namespace App\Jobs;

use App\Models\MapsScrapeJob;
use App\Services\MapsScraper\MapsScraperService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RunMapsScrapeJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 7500;

    public function __construct(
        public int $jobId,
    ) {}

    public function handle(MapsScraperService $scraper): void
    {
        $job = MapsScrapeJob::query()->findOrFail($this->jobId);
        $scraper->run($job);
    }

    public function failed(?Throwable $exception): void
    {
        $job = MapsScrapeJob::query()->find($this->jobId);
        if (! $job) {
            return;
        }

        $job->update([
            'status' => 'failed',
            'progress_message' => 'Failed',
            'error_message' => $exception?->getMessage() ?: 'Unknown scraper failure',
        ]);
    }
}
