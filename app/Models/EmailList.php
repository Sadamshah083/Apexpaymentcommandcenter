<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class EmailList extends Model
{
    protected $fillable = [
        'workspace_id',
        'user_id',
        'name',
        'source_file',
        'total_count',
        'status',
        'notes',
        'valid_count',
        'invalid_count',
        'risky_count',
        'unknown_count',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(EmailContact::class);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(VerificationBatch::class);
    }

    public function latestBatch(): HasOne
    {
        return $this->hasOne(VerificationBatch::class)->latestOfMany();
    }

    public function refreshCounts(): void
    {
        $this->update([
            'valid_count' => $this->contacts()->where('status', 'valid')->count(),
            'invalid_count' => $this->contacts()->where('status', 'invalid')->count(),
            'risky_count' => $this->contacts()->where('status', 'risky')->count(),
            'unknown_count' => $this->contacts()->where('status', 'unknown')->count(),
        ]);
    }

    public function getProgressPercentAttribute(): float
    {
        $batch = $this->latestBatch;

        if (! $batch || $batch->total === 0) {
            return 0;
        }

        return round(($batch->processed / $batch->total) * 100, 1);
    }
}
