<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReputationLog extends Model
{
    protected $fillable = [
        'domain',
        'metric',
        'value',
        'notes',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'recorded_at' => 'date',
        ];
    }
}
