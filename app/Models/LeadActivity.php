<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadActivity extends Model
{
    protected $fillable = [
        'workflow_lead_id',
        'user_id',
        'type',
        'outcome',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(WorkflowLead::class, 'workflow_lead_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isStatusChange(): bool
    {
        return in_array($this->type, ['setter_status_change', 'closer_status_change'], true);
    }

    public function statusRole(): ?string
    {
        return match ($this->type) {
            'setter_status_change' => 'setter',
            'closer_status_change' => 'closer',
            default => $this->metadata['role'] ?? null,
        };
    }

    public function statusFrom(): ?string
    {
        return $this->metadata['from'] ?? null;
    }

    public function statusTo(): ?string
    {
        return $this->metadata['to'] ?? null;
    }
}
