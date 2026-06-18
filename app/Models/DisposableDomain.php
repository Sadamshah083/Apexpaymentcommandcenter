<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DisposableDomain extends Model
{
    protected $fillable = [
        'domain',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'synced_at' => 'datetime',
        ];
    }
}
