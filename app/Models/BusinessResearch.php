<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BusinessResearch extends Model
{
    protected $table = 'business_researches';

    protected $fillable = [
        'workspace_id',
        'user_id',
        'business_name',
        'address',
        'website',
        'status',
        'owner_name',
        'owner_title',
        'co_owners',
        'emails',
        'phones',
        'payment_processor',
        'pos_system',
        'field_service_software',
        'business_type',
        'is_franchise',
        'franchise_brand',
        'summary',
        'structured_data',
        'sources',
        'search_queries',
        'confidence',
        'raw_response',
        'error_message',
        'model_used',
        'tokens_used',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'co_owners' => 'array',
            'emails' => 'array',
            'phones' => 'array',
            'structured_data' => 'array',
            'sources' => 'array',
            'search_queries' => 'array',
            'is_franchise' => 'boolean',
            'completed_at' => 'datetime',
        ];
    }

    public function isComplete(): bool
    {
        return in_array($this->status, ['completed', 'failed'], true);
    }
}
