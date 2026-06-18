<?php

namespace App\Services\EmailList;

use App\Jobs\ProcessListUploadJob;
use App\Jobs\VerifyEmailChunkJob;
use App\Models\EmailList;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class EmailListService
{
    public function createFromUpload(
        Workspace $workspace,
        User $user,
        string $name,
        UploadedFile $file,
        ?string $notes = null,
    ): EmailList {
        $path = $file->store('uploads', 'local');

        $list = EmailList::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'name' => $name,
            'source_file' => $file->getClientOriginalName(),
            'status' => 'pending',
            'notes' => $notes,
        ]);

        ProcessListUploadJob::dispatch($list->id, $path);

        return $list;
    }

    public function pause(EmailList $list): void
    {
        if (! in_array($list->status, ['verifying', 'processing'], true)) {
            return;
        }

        $list->update(['status' => 'paused']);
    }

    public function resume(EmailList $list): void
    {
        if ($list->status !== 'paused') {
            return;
        }

        $list->update(['status' => 'verifying']);

        $batch = $list->latestBatch;
        if (! $batch) {
            return;
        }

        $pendingIds = $list->contacts()->where('status', 'pending')->pluck('id')->all();
        $chunkSize = config('email_checker.verification.chunk_size', 50);

        foreach (array_chunk($pendingIds, $chunkSize) as $chunk) {
            if ($chunk !== []) {
                VerifyEmailChunkJob::dispatch($list->id, $batch->id, $chunk);
            }
        }

        if ($pendingIds === [] && $batch->processed >= $batch->total) {
            $batch->update(['finished_at' => now()]);
            $list->refreshCounts();
            $list->update(['status' => 'completed']);
        }
    }

    public function delete(EmailList $list): void
    {
        $list->delete();
    }

    public function exportRows(EmailList $list, string $filter): iterable
    {
        $query = $list->contacts()->with('results');

        if ($filter === 'valid') {
            $query->where('status', 'valid');
        } elseif ($filter === 'valid_risky') {
            $query->whereIn('status', ['valid', 'risky']);
        }

        foreach ($query->cursor() as $contact) {
            $summary = $contact->verificationSummary();

            yield [
                $contact->email,
                $contact->domain,
                $contact->status,
                $contact->final_score,
                implode(',', $contact->tags ?? []),
                $summary['mx'],
                $summary['smtp'],
                $summary['disposable'],
                $summary['provider'],
                $contact->failure_reason,
            ];
        }
    }
}
