<?php

namespace App\Jobs;

use App\Models\EmailContact;
use App\Models\EmailList;
use App\Models\VerificationBatch;
use App\Services\Verification\EmailVerificationPipeline;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class VerifyEmailChunkJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(
        public int $emailListId,
        public int $batchId,
        public array $contactIds,
    ) {}

    public function handle(EmailVerificationPipeline $pipeline): void
    {
        $batch = VerificationBatch::findOrFail($this->batchId);
        $list = EmailList::findOrFail($this->emailListId);

        if ($list->status === 'paused') {
            VerifyEmailChunkJob::dispatch($this->emailListId, $this->batchId, $this->contactIds)
                ->delay(now()->addSeconds(10));

            return;
        }

        foreach ($this->contactIds as $contactId) {
            $list->refresh();
            if ($list->status === 'paused') {
                $index = array_search($contactId, $this->contactIds, true);
                $remaining = $index === false
                    ? $this->contactIds
                    : array_slice($this->contactIds, $index);

                if ($remaining !== []) {
                    VerifyEmailChunkJob::dispatch($this->emailListId, $this->batchId, $remaining)
                        ->delay(now()->addSeconds(10));
                }

                return;
            }

            try {
                $contact = EmailContact::find($contactId);
                if ($contact && $contact->status === 'pending') {
                    $pipeline->verify($contact);
                }
                $batch->increment('processed');
            } catch (\Throwable $e) {
                Log::error('Email chunk verification failed for contact', [
                    'email_list_id' => $this->emailListId,
                    'batch_id' => $this->batchId,
                    'contact_id' => $contactId,
                    'error' => $e->getMessage(),
                ]);
                $batch->increment('failed');
                $batch->increment('processed');
            }
        }

        $batch->refresh();

        if ($batch->processed >= $batch->total) {
            $batch->update(['finished_at' => now()]);
            $list->refreshCounts();
            if ($list->status !== 'paused') {
                $list->update(['status' => 'completed']);
            }
        }
    }
}
