<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrmCampaign extends Model
{
    protected $fillable = [
        'name',
        'original_filename',
        'status',
        'total_leads',
        'pending_count',
        'processing_count',
        'completed_count',
        'failed_count',
        'csv_headers',
        'column_mapping',
        'import_error',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'csv_headers' => 'array',
            'column_mapping' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function leads(): HasMany
    {
        return $this->hasMany(CrmLead::class, 'campaign_id');
    }

    public function refreshCounts(): void
    {
        $counts = $this->leads()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $pending = (int) ($counts['pending'] ?? 0);
        $processing = (int) ($counts['processing'] ?? 0);
        $completed = (int) ($counts['completed'] ?? 0);
        $failed = (int) ($counts['failed'] ?? 0);
        $skipped = (int) ($counts['skipped'] ?? 0);
        $total = $pending + $processing + $completed + $failed + $skipped;

        $updates = [
            'total_leads' => $total,
            'pending_count' => $pending,
            'processing_count' => $processing,
            'completed_count' => $completed,
            'failed_count' => $failed,
        ];

        if ($total > 0) {
            if ($pending === 0 && $processing === 0) {
                $updates['status'] = $failed > 0 && $completed === 0 ? 'failed' : 'completed';
                $updates['completed_at'] = now();
            } else {
                $updates['status'] = 'processing';
                $updates['completed_at'] = null;
            }
        }

        $this->update($updates);
    }

    public function refreshCountsThrottled(?int $intervalSeconds = null): void
    {
        $intervalSeconds ??= config('crm.refresh_counts_interval', 5);
        $key = 'crm_campaign_refresh_'.$this->id;

        if (! \Illuminate\Support\Facades\Cache::has($key)) {
            \Illuminate\Support\Facades\Cache::put($key, true, $intervalSeconds);
            $this->refreshCounts();
        }
    }

    public function progressPercent(): float
    {
        if ($this->total_leads === 0) {
            return 0;
        }

        return round((($this->completed_count + $this->failed_count) / $this->total_leads) * 100, 1);
    }

    public function isComplete(): bool
    {
        return in_array($this->status, ['completed', 'failed'], true);
    }
}
