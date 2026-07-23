<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationError extends Model
{
    protected $fillable = [
        'level',
        'exception_class',
        'message',
        'trace',
        'file',
        'line',
        'url',
        'method',
        'user_id',
        'ip',
        'user_agent',
        'occurrences',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'line' => 'integer',
            'occurrences' => 'integer',
            'user_id' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
