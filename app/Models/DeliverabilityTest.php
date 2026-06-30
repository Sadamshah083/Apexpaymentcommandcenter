<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliverabilityTest extends Model
{
    protected $fillable = [
        'workspace_id',
        'user_id',
        'domain',
        'sending_ip',
        'dkim_selector',
        'spf_result',
        'dkim_result',
        'dmarc_result',
        'mx_result',
        'ptr_result',
        'dnsbl_result',
        'overall_score',
        'recommendations',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'spf_result' => 'array',
            'dkim_result' => 'array',
            'dmarc_result' => 'array',
            'mx_result' => 'array',
            'ptr_result' => 'array',
            'dnsbl_result' => 'array',
            'recommendations' => 'array',
            'overall_score' => 'decimal:2',
        ];
    }
}
