<?php

namespace App\Services\Crm;

class CrmAddressParser
{
    /**
     * Parse a single-line US-style address into components.
     *
     * @return array{address: ?string, city: ?string, state: ?string, zip_code: ?string}
     */
    public function parse(?string $fullAddress): array
    {
        $fullAddress = trim((string) $fullAddress);

        if ($fullAddress === '') {
            return ['address' => null, 'city' => null, 'state' => null, 'zip_code' => null];
        }

        // "1065 Richmond Ave Suite 140, Houston, TX 77006"
        if (preg_match('/^(.+?),\s*([^,]+),\s*([A-Z]{2})\s*(\d{5}(?:-\d{4})?)\s*$/i', $fullAddress, $m)) {
            return [
                'address' => trim($m[1]),
                'city' => trim($m[2]),
                'state' => strtoupper(trim($m[3])),
                'zip_code' => trim($m[4]),
            ];
        }

        // "1065 Richmond Ave, Houston, TX 77006" (no suite in middle)
        if (preg_match('/^(.+?),\s*([^,]+),\s*([A-Z]{2})\s*(\d{5}(?:-\d{4})?)?\s*$/i', $fullAddress, $m)) {
            return [
                'address' => trim($m[1]),
                'city' => trim($m[2]),
                'state' => strtoupper(trim($m[3])),
                'zip_code' => isset($m[4]) ? trim($m[4]) : null,
            ];
        }

        // "Houston, TX 77006" (city state zip only — store as city/state)
        if (preg_match('/^([^,]+),\s*([A-Z]{2})\s*(\d{5}(?:-\d{4})?)?\s*$/i', $fullAddress, $m)) {
            return [
                'address' => null,
                'city' => trim($m[1]),
                'state' => strtoupper(trim($m[2])),
                'zip_code' => isset($m[3]) ? trim($m[3]) : null,
            ];
        }

        return ['address' => $fullAddress, 'city' => null, 'state' => null, 'zip_code' => null];
    }

    /**
     * @param  array{address: ?string, city: ?string, state: ?string, zip_code: ?string}  $parts
     * @return array{address: ?string, city: ?string, state: ?string, zip_code: ?string}
     */
    public function mergeParts(array $parts, ?string $address, ?string $city, ?string $state, ?string $zip): array
    {
        return [
            'address' => $address ?: $parts['address'],
            'city' => $city ?: $parts['city'],
            'state' => $state ?: $parts['state'],
            'zip_code' => $zip ?: $parts['zip_code'],
        ];
    }
}
