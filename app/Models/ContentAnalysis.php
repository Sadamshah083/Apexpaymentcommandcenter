<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContentAnalysis extends Model
{
    protected $fillable = [
        'workspace_id',
        'user_id',
        'title',
        'subject',
        'html_body',
        'text_body',
        'scores',
        'highlights',
        'suggestions',
        'overall_score',
        'spam_score',
    ];

    protected function casts(): array
    {
        return [
            'scores' => 'array',
            'highlights' => 'array',
            'suggestions' => 'array',
            'overall_score' => 'decimal:2',
            'spam_score' => 'decimal:2',
        ];
    }
}
