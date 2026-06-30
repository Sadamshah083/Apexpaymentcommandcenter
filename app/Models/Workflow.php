<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Workflow extends Model
{
    protected $fillable = [
        'workspace_id',
        'name',
        'status',
        'processing_mode',
        'original_filename',
        'file_path',
        'sheets',
        'selected_sheet',
        'column_mapping',
        'total_leads',
        'processed_leads',
        'failed_leads',
        'error_message',
        'custom_prompt',
        'verification_toggles',
        'distribution_users',
        'distribution_cursor',
        'ingestion_row_offset',
        'ingestion_complete',
    ];

    protected $casts = [
        'sheets' => 'array',
        'column_mapping' => 'array',
        'verification_toggles' => 'array',
        'distribution_users' => 'array',
        'ingestion_complete' => 'boolean',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(WorkflowLead::class);
    }

    public function isProcessing(): bool
    {
        return in_array($this->status, ['pending', 'extracting'], true);
    }

    public function isPaused(): bool
    {
        return $this->status === 'paused';
    }

    public function isStoreOnly(): bool
    {
        return $this->processing_mode === 'store_only';
    }

    public function isFullPipeline(): bool
    {
        return $this->processing_mode !== 'store_only';
    }
}
