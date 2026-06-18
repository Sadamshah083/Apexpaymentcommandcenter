<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailContact extends Model
{
    protected $fillable = [
        'email_list_id',
        'email',
        'normalized_email',
        'domain',
        'status',
        'final_score',
        'tags',
        'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'final_score' => 'decimal:2',
        ];
    }

    public function emailList(): BelongsTo
    {
        return $this->belongsTo(EmailList::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(VerificationResult::class);
    }

    /**
     * @return array{mx: string|null, smtp: string|null, disposable: string|null, provider: string|null}
     */
    public function verificationSummary(): array
    {
        $byStage = $this->relationLoaded('results')
            ? $this->results->keyBy('stage')
            : $this->results()->get()->keyBy('stage');

        $mx = $byStage->get('mx');
        $smtp = $byStage->get('smtp');
        $disposable = $byStage->get('disposable');
        $provider = $byStage->get('free_provider');

        $mxLabel = null;
        if ($mx) {
            $hosts = $mx->metadata['mx_records'] ?? [];
            $mxLabel = ! empty($hosts)
                ? ($hosts[0]['host'] ?? 'MX found')
                : ($mx->message ?? 'OK');
        }

        return [
            'mx' => $mxLabel,
            'smtp' => $smtp?->status,
            'disposable' => $disposable && $disposable->status === 'invalid' ? 'Yes' : 'No',
            'provider' => ($provider?->metadata['is_free_provider'] ?? false) ? 'Free' : 'Business',
        ];
    }
}
