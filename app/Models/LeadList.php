<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class LeadList extends Model
{
    protected $fillable = [
        'workspace_id',
        'name',
        'slug',
        'description',
        'created_by',
    ];

    protected static function booted(): void
    {
        static::creating(function (LeadList $list) {
            if (empty($list->slug)) {
                $list->slug = static::uniqueSlug($list->workspace_id, $list->name);
            }
        });
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function leads(): HasMany
    {
        return $this->hasMany(WorkflowLead::class);
    }

    public function workflows(): HasMany
    {
        return $this->hasMany(Workflow::class);
    }

    public static function uniqueSlug(int $workspaceId, string $name): string
    {
        $base = Str::slug($name) ?: 'list';
        $slug = $base;
        $i = 2;

        while (static::query()->where('workspace_id', $workspaceId)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i;
            $i++;
        }

        return $slug;
    }
}
