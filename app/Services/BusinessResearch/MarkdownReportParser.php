<?php

namespace App\Services\BusinessResearch;

class MarkdownReportParser
{
    /**
     * @return array<string, mixed>
     */
    public function parseContent(string $content): array
    {
        $json = $this->decodeJsonReport($content);
        if ($json !== null) {
            return $this->parseJsonReport($json);
        }

        return $this->parse($content);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function decodeJsonReport(string $content): ?array
    {
        $trimmed = trim($content);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^```(?:json)?\s*([\s\S]*?)\s*```$/i', $trimmed, $match)) {
            $trimmed = trim($match[1]);
        }

        if (! str_starts_with($trimmed, '{') && ! str_starts_with($trimmed, '[')) {
            return null;
        }

        $decoded = json_decode($trimmed, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array<string, mixed>
     */
    protected function parseJsonReport(array $json): array
    {
        return [
            'business_name_official' => $this->scalarField($json['business_name'] ?? $json['business_name_official'] ?? null),
            'physical_address' => $this->scalarField($json['address'] ?? $json['physical_address'] ?? null),
            'owner_name' => $this->scalarField($json['owner_name'] ?? null),
            'direct_phone' => $this->scalarField($json['phone_number'] ?? $json['direct_phone'] ?? $json['phone'] ?? null, true),
            'direct_email' => $this->scalarField($json['owner_email'] ?? $json['direct_email'] ?? $json['email'] ?? null),
            'payment_processor' => $this->scalarField($json['payment_processor'] ?? null),
            'system_integration' => $this->scalarField(
                $json['booking_pos_software'] ?? $json['system_integration'] ?? $json['pos_system'] ?? null,
                true,
            ),
            'website' => $this->scalarField($json['website'] ?? null),
            'primary_service' => $this->scalarField($json['primary_service'] ?? null),
            'operating_hours' => $this->scalarField($json['operating_hours'] ?? null),
        ];
    }

    protected function scalarField(mixed $value, bool $joinArrays = false): ?string
    {
        if (is_array($value)) {
            if ($value === []) {
                return null;
            }

            if ($joinArrays) {
                $parts = array_values(array_filter(array_map(
                    fn (mixed $item) => $this->scalarField($item),
                    $value,
                )));

                return $parts === [] ? null : implode(', ', $parts);
            }

            return $this->scalarField($value[0] ?? null);
        }

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '' || $this->isUnavailable($value)) {
            return null;
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function parse(string $markdown): array
    {
        $fields = [
            'business_name_official' => $this->extractField($markdown, 'Business Name'),
            'physical_address' => $this->extractField($markdown, 'Physical Address'),
            'primary_service' => $this->extractField($markdown, 'Primary Service'),
            'operating_hours' => $this->extractField($markdown, 'Operating Hours'),
            'owner_name' => $this->extractField($markdown, 'Direct Owner Name'),
            'direct_phone' => $this->extractField($markdown, 'Direct Phone Number'),
            'direct_email' => $this->extractField($markdown, 'Direct Email Address'),
            'payment_processor' => $this->extractField($markdown, 'Payment Processor'),
            'system_integration' => $this->extractField($markdown, 'System Integration'),
        ];

        $fields['phones'] = $this->toPhoneList($fields['direct_phone']);
        $fields['emails'] = $this->toEmailList($fields['direct_email']);
        $fields['business_type'] = $fields['primary_service'];
        $fields['field_service_software'] = $this->guessFieldSoftware($fields['system_integration'], $markdown);
        $fields['pos_system'] = $fields['field_service_software'];
        $fields['summary'] = $this->buildSummary($fields);
        $fields['confidence'] = $this->estimateConfidence($fields);
        $fields['is_franchise'] = $this->detectFranchise($markdown);
        $fields['franchise_brand'] = $this->extractFranchiseBrand($markdown);

        return $fields;
    }

    protected function extractField(string $markdown, string $label): ?string
    {
        $lines = preg_split('/\r\n|\n/', $markdown);

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '###')) {
                continue;
            }

            $patterns = [
                '/^\*\s+\*\*'.preg_quote($label, '/').'\*\*\s*:\s*(.+)$/i',
                '/^\*\*'.preg_quote($label, '/').'\*\*\s*:\s*(.+)$/i',
                '/^'.preg_quote($label, '/').'\s*:\s*(.+)$/i',
            ];

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $line, $match)) {
                    $value = $this->cleanValue($match[1]);

                    if ($value !== null) {
                        return $this->capField($label, $value);
                    }
                }
            }
        }

        return null;
    }

    protected function capField(string $label, string $value): string
    {
        $limits = [
            'Direct Owner Name' => 500,
            'Direct Phone Number' => 80,
            'Direct Email Address' => 255,
            'Physical Address' => 500,
            'Primary Service' => 1000,
            'Operating Hours' => 1000,
            'Payment Processor' => 255,
            'System Integration' => 2000,
            'Business Name' => 500,
        ];

        $max = $limits[$label] ?? 500;

        return mb_substr($value, 0, $max);
    }

    protected function cleanValue(string $raw): ?string
    {
        $raw = $this->stripTrailingFields($raw);

        // Stop if another markdown field starts on the same line
        $raw = preg_split('/\s+\*\s+\*\*/', $raw)[0] ?? $raw;
        $raw = preg_split('/\s+\*\*\s*[A-Z]/', $raw)[0] ?? $raw;

        $value = trim(preg_replace('/\s+/', ' ', $raw));
        $value = preg_replace('/^\[|\]$/', '', $value);
        $value = preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', $value);
        $value = trim($value, '[] *');

        if ($value === '' || $this->isUnavailable($value)) {
            return null;
        }

        return $value;
    }

    protected function stripTrailingFields(string $raw): string
    {
        $labels = [
            'Business Name',
            'Physical Address',
            'Primary Service',
            'Operating Hours',
            'Direct Owner Name',
            'Direct Phone Number',
            'Direct Email Address',
            'Payment Processor',
            'System Integration',
        ];

        $earliest = strlen($raw);

        foreach ($labels as $label) {
            foreach ([
                '* **'.$label.'**',
                '**'.$label.'**',
                '* '.$label.':',
            ] as $needle) {
                $pos = stripos($raw, $needle);
                if ($pos !== false && $pos > 0 && $pos < $earliest) {
                    $earliest = $pos;
                }
            }
        }

        return trim(substr($raw, 0, $earliest));
    }

    protected function isUnavailable(string $value): bool
    {
        $lower = strtolower($value);

        return str_contains($lower, 'not publicly available')
            || str_contains($lower, 'not available')
            || str_contains($lower, 'unknown')
            || $lower === 'n/a';
    }

    /**
     * @return array<int, string>
     */
    protected function toEmailList(?string $value): array
    {
        if (! $value) {
            return [];
        }

        if (preg_match_all('/[\w.+-]+@[\w.-]+\.[a-z]{2,}/i', $value, $emails)) {
            return array_values(array_unique($emails[0]));
        }

        return [];
    }

    /**
     * @return array<int, string>
     */
    protected function toPhoneList(?string $value): array
    {
        if (! $value) {
            return [];
        }

        if (preg_match_all('/(?:\+?1[-.\s]?)?\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}/', $value, $phones)) {
            return array_values(array_unique($phones[0]));
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    protected function buildSummary(array $fields): string
    {
        $parts = [];

        if (! empty($fields['owner_name'])) {
            $parts[] = 'Owner: '.$fields['owner_name'];
        }
        if (! empty($fields['payment_processor'])) {
            $parts[] = 'Processor: '.$fields['payment_processor'];
        }
        if (! empty($fields['field_service_software'])) {
            $parts[] = 'Software: '.$fields['field_service_software'];
        }
        if (! empty($fields['system_integration'])) {
            $parts[] = mb_substr($fields['system_integration'], 0, 200);
        }

        return implode('. ', $parts) ?: 'See full report below.';
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    protected function estimateConfidence(array $fields): string
    {
        $score = 0;

        foreach (['owner_name', 'payment_processor', 'direct_phone', 'direct_email', 'physical_address'] as $field) {
            if (! empty($fields[$field])) {
                $score++;
            }
        }

        return match (true) {
            $score >= 4 => 'high',
            $score >= 2 => 'medium',
            default => 'low',
        };
    }

    protected function guessFieldSoftware(?string $integration, string $markdown): ?string
    {
        $haystack = strtolower(($integration ?? '').' '.$markdown);
        $candidates = [
            'ServiceTitan', 'Housecall Pro', 'Jobber', 'Square', 'Clover', 'Toast',
            'Booksy', 'Mindbody', 'Fresha', 'QuickBooks', 'Stripe', 'Shopify',
            'Worldpay', 'Fiserv', 'Schedulicity', 'vcita', 'Zenoti',
        ];

        foreach ($candidates as $name) {
            if (str_contains($haystack, strtolower($name))) {
                return $name;
            }
        }

        return null;
    }

    protected function detectFranchise(string $markdown): bool
    {
        $lower = strtolower($markdown);

        return str_contains($lower, 'franchise')
            || str_contains($lower, 'franchisee')
            || str_contains($lower, 'locally owned and operated');
    }

    protected function extractFranchiseBrand(string $markdown): ?string
    {
        if (preg_match('/franchise(?:e)?\s+(?:of\s+)?([A-Z][A-Za-z0-9\s&\'-]+)/i', $markdown, $match)) {
            return trim($match[1]);
        }

        return null;
    }

    public function hasReportSchema(string $content): bool
    {
        return str_contains($content, '### Business Identity')
            || str_contains($content, '**Direct Owner Name**')
            || str_contains($content, '**Payment Processor**')
            || str_contains($content, 'Direct Owner Name');
    }

    public function isValidReport(string $content): bool
    {
        if (! $this->hasReportSchema($content)) {
            return false;
        }

        $parsed = $this->parse($content);

        return $this->score($parsed) > 0;
    }

    /**
     * CRM bulk: owner name alone (or any key contact/processor field) is enough to save.
     *
     * @param  array<string, mixed>  $parsed
     */
    public function hasMinimumUsefulData(array $parsed): bool
    {
        if (! empty($parsed['owner_name'])) {
            return true;
        }

        $filled = 0;
        foreach ([
            'payment_processor',
            'direct_phone',
            'direct_email',
            'pos_system',
            'field_service_software',
            'primary_service',
            'business_name_official',
            'physical_address',
            'operating_hours',
        ] as $field) {
            if (! empty($parsed[$field])) {
                $filled++;
            }
        }

        return $filled >= 1;
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    protected function score(array $parsed): int
    {
        $score = 0;
        foreach (['owner_name', 'payment_processor', 'direct_phone', 'direct_email', 'physical_address'] as $field) {
            if (! empty($parsed[$field])) {
                $score++;
            }
        }

        return $score;
    }
}
