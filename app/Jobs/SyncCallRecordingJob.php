<?php

namespace App\Jobs;

use App\Models\CommunicationCallLog;
use App\Services\Communications\CommunicationsCallRecordingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncCallRecordingJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public int $callLogId,
        public int $attempt = 1,
    ) {}

    public function handle(CommunicationsCallRecordingService $recordings): void
    {
        $log = CommunicationCallLog::query()->find($this->callLogId);
        if (! $log) {
            return;
        }

        $log = $recordings->resolveAndPersist($log, $this->attempt);

        if ($log->recording_status === CommunicationsCallRecordingService::STATUS_PENDING && $this->attempt < 6) {
            self::dispatch($this->callLogId, $this->attempt + 1)
                ->delay(now()->addSeconds($this->backoffSeconds()));
        }
    }

    protected function backoffSeconds(): int
    {
        return match ($this->attempt) {
            1 => 20,
            2 => 45,
            3 => 90,
            4 => 180,
            5 => 300,
            default => 60,
        };
    }
}
