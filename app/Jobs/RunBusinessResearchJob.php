<?php

namespace App\Jobs;

use App\Models\BusinessResearch;
use App\Services\BusinessResearch\BusinessResearchService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunBusinessResearchJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(
        public int $researchId,
    ) {}

    public function handle(BusinessResearchService $service): void
    {
        $research = BusinessResearch::findOrFail($this->researchId);
        $service->research($research);
    }
}
