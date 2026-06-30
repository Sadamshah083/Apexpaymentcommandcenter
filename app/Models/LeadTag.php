<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class LeadTag extends Model
{
    protected $fillable = [
        'workspace_id',
        'name',
        'color',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function leads(): BelongsToMany
    {
        return $this->belongsToMany(WorkflowLead::class, 'lead_tag_workflow_lead', 'lead_tag_id', 'workflow_lead_id');
    }
}
