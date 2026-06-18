<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class InboundTestInbox extends Model
{
    protected $fillable = [
        'uuid',
        'email_address',
        'status',
        'expires_at',
        'parsed_headers',
        'auth_results',
        'raw_message',
        'content_analysis_id',
        'deliverability_test_id',
        'overall_score',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'parsed_headers' => 'array',
            'auth_results' => 'array',
            'overall_score' => 'decimal:2',
        ];
    }

    public function contentAnalysis(): BelongsTo
    {
        return $this->belongsTo(ContentAnalysis::class);
    }

    public function deliverabilityTest(): BelongsTo
    {
        return $this->belongsTo(DeliverabilityTest::class);
    }

    public static function createInbox(): self
    {
        $uuid = (string) Str::uuid();
        $domain = config('email_checker.inbound.domain', 'test.local');

        return self::create([
            'uuid' => $uuid,
            'email_address' => "test-{$uuid}@{$domain}",
            'status' => 'waiting',
            'expires_at' => now()->addHours(config('email_checker.inbound.inbox_ttl_hours', 24)),
        ]);
    }
}
