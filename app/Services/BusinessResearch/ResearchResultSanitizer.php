<?php

namespace App\Services\BusinessResearch;

class ResearchResultSanitizer
{
    /** @var array<string, int> */
    protected array $limits = [
        'owner_name' => 500,
        'owner_title' => 255,
        'direct_phone' => 80,
        'direct_email' => 255,
        'physical_address' => 500,
        'primary_service' => 1000,
        'operating_hours' => 1000,
        'payment_processor' => 255,
        'pos_system' => 255,
        'field_service_software' => 255,
        'business_type' => 500,
        'franchise_brand' => 255,
        'summary' => 2000,
        'confidence' => 20,
        'model_used' => 120,
        'error_message' => 2000,
    ];

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function sanitize(array $attributes): array
    {
        foreach ($this->limits as $field => $max) {
            if (! isset($attributes[$field]) || ! is_string($attributes[$field])) {
                continue;
            }

            $attributes[$field] = $this->truncate($attributes[$field], $max);
        }

        return $attributes;
    }

    protected function truncate(string $value, int $max): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value));

        return mb_substr($value, 0, $max);
    }
}
