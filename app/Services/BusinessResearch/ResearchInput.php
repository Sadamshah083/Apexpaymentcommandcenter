<?php

namespace App\Services\BusinessResearch;

use App\Models\BusinessResearch;
use App\Models\CrmLead;

class ResearchInput
{
    public function __construct(
        public string $businessName,
        public ?string $address = null,
        public ?string $website = null,
        public ?string $sheetContext = null,
    ) {}

    public static function fromBusinessResearch(BusinessResearch $research): self
    {
        return new self(
            businessName: trim($research->business_name),
            address: $research->address ? trim($research->address) : null,
            website: $research->website ? trim($research->website) : null,
        );
    }

    public static function fromCrmLead(CrmLead $lead): self
    {
        $sheetLines = [];

        if ($lead->input_phone) {
            $sheetLines[] = 'Phone from sheet: '.$lead->input_phone;
        }
        if ($lead->input_email) {
            $sheetLines[] = 'Email from sheet: '.$lead->input_email;
        }

        foreach ($lead->extra_fields ?? [] as $key => $value) {
            if ($value !== '' && strtolower($value) !== 'none found') {
                $sheetLines[] = $key.': '.$value;
            }
        }

        return new self(
            businessName: trim($lead->business_name),
            address: $lead->researchAddress() !== 'Not specified' ? $lead->researchAddress() : null,
            website: $lead->website ? trim($lead->website) : null,
            sheetContext: $sheetLines ? implode("\n", $sheetLines) : null,
        );
    }
}
