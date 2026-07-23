<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadDisposition extends Model
{
    protected $fillable = [
        'workspace_id',
        'user_id',
        'workflow_lead_id',
        'communication_call_log_id',
        'phone',
        'call_uuid',
        'disposition',
        'note',
        'duration_sec',
        'dial_mode',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'duration_sec' => 'integer',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(WorkflowLead::class, 'workflow_lead_id');
    }

    public function callLog(): BelongsTo
    {
        return $this->belongsTo(CommunicationCallLog::class, 'communication_call_log_id');
    }
}
