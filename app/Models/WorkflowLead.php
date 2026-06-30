<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowLead extends Model
{
    protected $fillable = [
        'workflow_id',
        'lead_list_id',
        'import_mode',
        'pipeline_phase',
        'setter_status',
        'closer_status',
        'assigned_user_id',
        'assigned_setter_id',
        'assigned_closer_id',
        'appointment_settled_at',
        'handoff_notes',
        'status',
        'verification_status',
        'verification_snapshot',
        'verified_at',
        'verified_by',
        'rejection_reason',
        'row_number',
        'business_name',
        'address',
        'city',
        'state',
        'zip_code',
        'country',
        'website',
        'input_phone',
        'normalized_phone',
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
        'contact_attempts',
        'tier',
        'sale_value',
        'monthly_processing_volume',
        'current_processor',
        'pricing_model',
        'contract_expiration_date',
        'pain_points',
        'offer_type',
        'discovery_completed_at',
        'discovery_completed_by',
        'meeting_qualified',
        'meeting_qualified_at',
        'reactivation_source',
        'reactivation_eligible_at',
        'is_nurture',
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
        'verification_snapshot' => 'array',
        'verified_at' => 'datetime',
        'followup_at' => 'datetime',
        'schedule_at' => 'datetime',
        'last_contacted_at' => 'datetime',
        'researched_at' => 'datetime',
        'appointment_settled_at' => 'datetime',
        'sale_value' => 'decimal:2',
        'monthly_processing_volume' => 'decimal:2',
        'pain_points' => 'array',
        'contract_expiration_date' => 'date',
        'discovery_completed_at' => 'datetime',
        'meeting_qualified_at' => 'datetime',
        'reactivation_eligible_at' => 'datetime',
        'is_nurture' => 'boolean',
        'meeting_qualified' => 'boolean',
        'contact_attempts' => 'integer',
    ];

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function leadList(): BelongsTo
    {
        return $this->belongsTo(LeadList::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(LeadTag::class, 'lead_tag_workflow_lead', 'workflow_lead_id', 'lead_tag_id');
    }

    public function isEnriched(): bool
    {
        return in_array($this->status, ['enriched', 'completed', 'pending_verification'], true)
            || filled($this->researched_at);
    }

    public function isReadyForDistribution(): bool
    {
        return $this->status === 'enriched' && ! $this->assigned_user_id;
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function setter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_setter_id');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_closer_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(LeadAssignment::class, 'workflow_lead_id')->latest();
    }

    public function isSetterLocked(): bool
    {
        return in_array($this->pipeline_phase, ['appointment_settled', 'with_closer', 'closed'], true);
    }

    public function isCloserLocked(): bool
    {
        return $this->pipeline_phase === 'closed';
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function discoveryAuthor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'discovery_completed_by');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivity::class, 'workflow_lead_id')->latest();
    }

    public function isAwaitingVerification(): bool
    {
        return $this->status === 'pending_verification';
    }

    public function isReleasedToCrm(): bool
    {
        return $this->status === 'completed';
    }
}
