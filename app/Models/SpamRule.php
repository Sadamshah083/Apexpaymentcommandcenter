<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SpamRule extends Model
{
    protected $fillable = [
        'category',
        'name',
        'pattern',
        'match_type',
        'weight',
        'target',
        'description',
        'suggestion',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }
}
