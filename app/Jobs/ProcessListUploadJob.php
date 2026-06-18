<?php

namespace App\Jobs;

use App\Models\EmailContact;
use App\Models\EmailList;
use App\Models\VerificationBatch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

class ProcessListUploadJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public int $emailListId,
        public string $filePath,
    ) {}

    public function handle(): void
    {
        $list = EmailList::findOrFail($this->emailListId);
        $list->update(['status' => 'processing']);

        $content = Storage::disk('local')->get($this->filePath);
        $lines = preg_split('/\r\n|\r|\n/', $content);
        $emails = [];
        $seen = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = str_getcsv($line);
            $email = strtolower(trim($parts[0] ?? ''));

            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            if (isset($seen[$email])) {
                continue;
            }

            $seen[$email] = true;
            $emails[] = $email;
        }

        $chunkSize = config('email_checker.verification.chunk_size', 50);
        $chunks = array_chunk($emails, $chunkSize);

        $batch = VerificationBatch::create([
            'email_list_id' => $list->id,
            'total' => count($emails),
            'processed' => 0,
            'failed' => 0,
            'started_at' => now(),
        ]);

        foreach ($chunks as $chunk) {
            $contactIds = [];

            foreach ($chunk as $email) {
                $domain = explode('@', $email)[1] ?? '';
                $contact = EmailContact::create([
                    'email_list_id' => $list->id,
                    'email' => $email,
                    'normalized_email' => $email,
                    'domain' => $domain,
                    'status' => 'pending',
                ]);
                $contactIds[] = $contact->id;
            }

            VerifyEmailChunkJob::dispatch($list->id, $batch->id, $contactIds);
        }

        $list->update([
            'total_count' => count($emails),
            'status' => count($emails) > 0 ? 'verifying' : 'empty',
        ]);

        if (count($emails) === 0) {
            $batch->update(['finished_at' => now()]);
            $list->update(['status' => 'empty']);
        }

        Storage::disk('local')->delete($this->filePath);
    }
}
