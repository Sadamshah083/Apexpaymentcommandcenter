<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmLead extends Model
{
    protected $fillable = [
        'campaign_id',
        'row_number',
        'research_fingerprint',
        'status',
        'business_name',
        'address',
        'city',
        'state',
        'zip_code',
        'country',
        'website',
        'input_phone',
        'input_email',
        'raw_row',
        'extra_fields',
        'owner_name',
        'owner_title',
        'direct_phone',
        'direct_email',
        'phones',
        'emails',
        'physical_address',
        'primary_service',
        'operating_hours',
        'payment_processor',
        'pos_system',
        'field_service_software',
        'business_type',
        'is_franchise',
        'franchise_brand',
        'summary',
        'confidence',
        'structured_data',
        'sources',
        'search_queries',
        'raw_response',
        'error_message',
        'model_used',
        'tokens_used',
        'researched_at',
    ];

    protected function casts(): array
    {
        return [
            'raw_row' => 'array',
            'extra_fields' => 'array',
            'phones' => 'array',
            'emails' => 'array',
            'structured_data' => 'array',
            'sources' => 'array',
            'search_queries' => 'array',
            'is_franchise' => 'boolean',
            'researched_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(CrmCampaign::class, 'campaign_id');
    }

    public function fullAddress(): string
    {
        $address = trim($this->address ?? '');

        // Address column already contains full line (common with Google Maps CSV exports)
        if ($address !== '' && preg_match('/,\s*[A-Za-z .]+,\s*[A-Z]{2}(\s+\d{5})?/i', $address)) {
            return $address;
        }

        $parts = array_filter([
            $address ?: null,
            $this->city,
            $this->normalizedState(),
            $this->zip_code,
        ]);

        if (! empty($parts)) {
            return implode(', ', $parts);
        }

        return $address;
    }

    protected function normalizedState(): ?string
    {
        $state = trim($this->state ?? '');

        if ($state === '' || in_array(strtolower($state), ['no', 'yes', 'none', 'n/a', 'na'], true)) {
            return null;
        }

        return $state;
    }

    public function researchAddress(): string
    {
        $full = $this->fullAddress();

        if ($full !== '') {
            return $full;
        }

        return trim($this->city ?? '') ?: 'Not specified';
    }

    public function isComplete(): bool
    {
        return in_array($this->status, ['completed', 'failed', 'skipped'], true);
    }

    public function displayPhone(): ?string
    {
        return $this->direct_phone ?? $this->input_phone ?? ($this->phones[0] ?? null);
    }

    public function displayEmail(): ?string
    {
        return $this->direct_email ?? $this->input_email ?? ($this->emails[0] ?? null);
    }

    /** @param  \Illuminate\Database\Eloquent\Builder<CrmLead>  $query */
    public function scopeEnriched($query)
    {
        return $query->where('status', 'completed')->where(function ($q) {
            foreach (self::enrichedFieldNames() as $field) {
                $q->orWhere(function ($q) use ($field) {
                    $q->whereNotNull($field)->where($field, '!=', '');
                });
            }
        });
    }

    /** @param  \Illuminate\Database\Eloquent\Builder<CrmLead>  $query */
    public function scopeNotEnriched($query)
    {
        return $query->where(function ($q) {
            $q->where('status', '!=', 'completed')
                ->orWhere(function ($q) {
                    $q->where('status', 'completed');
                    foreach (self::enrichedFieldNames() as $field) {
                        $q->where(function ($q) use ($field) {
                            $q->whereNull($field)->orWhere($field, '');
                        });
                    }
                });
        });
    }

    public function isEnriched(): bool
    {
        if ($this->status !== 'completed') {
            return false;
        }

        foreach (self::enrichedFieldNames() as $field) {
            if (filled($this->{$field})) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, string> */
    protected static function enrichedFieldNames(): array
    {
        return [
            'owner_name',
            'payment_processor',
            'pos_system',
            'field_service_software',
            'direct_phone',
            'direct_email',
            'summary',
            'primary_service',
        ];
    }
}
