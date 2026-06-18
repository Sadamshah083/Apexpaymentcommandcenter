<?php

namespace App\Services\Crm;

use App\Models\CrmLead;

class CrmResearchCopier
{
    /** @var array<int, string> */
    protected array $researchFields = [
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
        'model_used',
        'tokens_used',
    ];

    public function findCachedSource(string $fingerprint, ?int $excludeLeadId = null): ?CrmLead
    {
        $query = CrmLead::query()
            ->where('research_fingerprint', $fingerprint)
            ->where('status', 'completed')
            ->whereNotNull('raw_response')
            ->orderByDesc('researched_at');

        if ($excludeLeadId) {
            $query->where('id', '!=', $excludeLeadId);
        }

        return $query->first();
    }

    public function copyToLead(CrmLead $target, CrmLead $source): void
    {
        $data = [];
        foreach ($this->researchFields as $field) {
            $data[$field] = $source->{$field};
        }

        $target->update(array_merge($data, [
            'status' => 'completed',
            'error_message' => null,
            'model_used' => ($source->model_used ?? 'gemini').' (cached)',
            'researched_at' => $source->researched_at ?? now(),
        ]));
    }

    /**
     * @param  array<string, mixed>  $incoming
     */
    public function inputChanged(CrmLead $lead, array $incoming): bool
    {
        $fields = ['business_name', 'address', 'city', 'state', 'zip_code', 'website', 'input_phone', 'input_email'];

        foreach ($fields as $field) {
            $current = trim((string) ($lead->{$field} ?? ''));
            $new = trim((string) ($incoming[$field] ?? ''));

            if ($current !== $new) {
                return true;
            }
        }

        return false;
    }
}
