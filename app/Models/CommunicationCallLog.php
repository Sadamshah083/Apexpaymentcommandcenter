<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommunicationCallLog extends Model
{
    protected $fillable = [
        'workspace_id',
        'user_id',
        'morpheus_call_uuid',
        'direction',
        'from_extension',
        'from_phone',
        'to_phone',
        'disposition',
        'note',
        'recording_file_id',
        'recording_source',
        'recording_status',
        'status',
        'duration_sec',
        'started_at',
        'ended_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
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
}
