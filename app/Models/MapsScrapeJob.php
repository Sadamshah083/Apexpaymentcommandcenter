<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MapsScrapeJob extends Model
{
    protected $fillable = [
        'workspace_id',
        'user_id',
        'job_mode',
        'state',
        'business',
        'search_query',
        'scrape_mode',
        'per_search',
        'small_business_only',
        'status',
        'progress_pct',
        'progress_message',
        'row_count',
        'file_count',
        'csv_path',
        'export_zip_path',
        'error_message',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'small_business_only' => 'boolean',
            'per_search' => 'integer',
            'progress_pct' => 'integer',
            'row_count' => 'integer',
            'file_count' => 'integer',
            'meta' => 'array',
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

    public function isComplete(): bool
    {
        return in_array($this->status, ['completed', 'failed'], true);
    }
}
