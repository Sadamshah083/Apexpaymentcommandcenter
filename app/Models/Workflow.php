<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Workflow extends Model
{
    protected $fillable = [
        'workspace_id',
        'lead_list_id',
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
        'enriched_leads',
        'failed_leads',
        'discarded_duplicates',
        'error_message',
        'custom_prompt',
        'verification_toggles',
        'distribution_users',
        'import_tag_ids',
        'auto_assign_setters',
        'distribution_cursor',
        'ingestion_row_offset',
        'ingestion_complete',
    ];

    protected $casts = [
        'sheets' => 'array',
        'column_mapping' => 'array',
        'verification_toggles' => 'array',
        'distribution_users' => 'array',
        'import_tag_ids' => 'array',
        'auto_assign_setters' => 'boolean',
        'ingestion_complete' => 'boolean',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function leadList(): BelongsTo
    {
        return $this->belongsTo(LeadList::class);
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
        return in_array($this->processing_mode, ['store_only', 'import_only'], true);
    }

    public function isImportOnly(): bool
    {
        return $this->isStoreOnly();
    }

    public function runsEnrichmentOnImport(): bool
    {
        return in_array($this->processing_mode, ['full_pipeline', 'import_and_enrich'], true);
    }

    public function shouldAutoAssignSetters(): bool
    {
        return (bool) $this->auto_assign_setters;
    }

    /** @deprecated Use isImportOnly() */
    public function isFullPipeline(): bool
    {
        return $this->runsEnrichmentOnImport();
    }
}
