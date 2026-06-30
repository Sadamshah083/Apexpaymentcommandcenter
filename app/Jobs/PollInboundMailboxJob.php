<?php

namespace App\Jobs;

use App\Services\Deliverability\InboundMailboxService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class PollInboundMailboxJob implements ShouldQueue
{
    use Queueable;

    public function handle(InboundMailboxService $mailbox): void
    {
        $processed = $mailbox->poll();

        if ($processed > 0) {
            Log::info('Inbound mailbox polling processed messages.', ['count' => $processed]);
        }
    }
}
