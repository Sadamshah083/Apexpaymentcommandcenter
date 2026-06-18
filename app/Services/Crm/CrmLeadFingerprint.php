<?php

namespace App\Services\Crm;

class CrmLeadFingerprint
{
    public function make(string $businessName, ?string $address, ?string $city, ?string $state, ?string $zip): string
    {
        $name = $this->normalize($businessName);
        $location = $this->normalize($address ?? '')
            .$this->normalize($city ?? '')
            .$this->normalize($state ?? '')
            .$this->normalizeZip($zip);

        return hash('sha256', $name.'|'.$location);
    }

    protected function normalize(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]/', '', $value);

        return $value ?? '';
    }

    protected function normalizeZip(?string $zip): string
    {
        if (! $zip) {
            return '';
        }

        return substr(preg_replace('/\D/', '', $zip), 0, 5);
    }
}
