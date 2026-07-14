<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Workspace extends Model
{
    protected $fillable = ['name', 'admin_id'];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_user')
            ->withPivot(
                'role',
                'team_lead_user_id',
                'campaign_id',
                'status',
                'invited_at',
                'joined_at',
                'module_permissions',
                'morpheus_user_id',
                'morpheus_extension_id',
                'morpheus_extension_num',
            )
            ->withTimestamps();
    }

    public function workflows(): HasMany
    {
        return $this->hasMany(Workflow::class);
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(LeadCampaign::class);
    }
}
