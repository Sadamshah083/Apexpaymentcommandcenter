<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeadCampaign extends Model
{
    protected $table = 'lead_campaigns';

    protected $fillable = [
        'workspace_id',
        'name',
        'description',
        'status',
        'created_by',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function workflows(): HasMany
    {
        return $this->hasMany(Workflow::class, 'campaign_id');
    }

    public function leads(): HasMany
    {
        return $this->hasMany(WorkflowLead::class, 'campaign_id');
    }
}
