<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowLead extends Model
{
    protected $fillable = [
        'workflow_id',
        'assigned_user_id',
        'status',
        'row_number',
        'business_name',
        'address',
        'city',
        'state',
        'zip_code',
        'country',
        'website',
        'input_phone',
        'input_email',
        'raw_row',
        'owner_name',
        'direct_phone',
        'direct_email',
        'payment_processor',
        'system_integration',
        'primary_service',
        'operating_hours',
        'markdown_report',
        'stage',
        'sale_value',
        'notes',
        'followup_at',
        'schedule_at',
        'last_contacted_at',
        'error_message',
        'model_used',
        'tokens_used',
        'researched_at'
    ];

    protected $casts = [
        'raw_row' => 'array',
        'followup_at' => 'datetime',
        'schedule_at' => 'datetime',
        'last_contacted_at' => 'datetime',
        'researched_at' => 'datetime',
        'sale_value' => 'decimal:2'
    ];

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }
}
