<?php

namespace App\Support;

use App\Models\WorkflowLead;
use App\Services\BusinessResearch\MarkdownReportParser;

class LeadContactDisplay
{
    /** @var array<string, array<string, mixed>> */
    protected static array $cache = [];

    public static function for(WorkflowLead $lead): array
    {
        $key = $lead->id
            ? (string) $lead->id
            : spl_object_hash($lead).':'.md5((string) $lead->markdown_report);

        if (! isset(self::$cache[$key])) {
            self::$cache[$key] = self::build($lead);
        }

        return self::$cache[$key];
    }

    public static function value(WorkflowLead $lead, string $field): ?string
    {
        return self::for($lead)[$field] ?? null;
    }

    public static function isJsonReport(?string $content): bool
    {
        return self::extractJsonPayload($content) !== null;
    }

    public static function shouldDisplayEnrichmentReport(?string $content): bool
    {
        if (! filled($content)) {
            return false;
        }

        if (self::extractJsonPayload($content) !== null) {
            return false;
        }

        $trimmed = trim((string) $content);

        return str_contains($trimmed, '###')
            || str_contains($trimmed, '**Business')
            || str_contains($trimmed, '**Direct Owner');
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function extractJsonPayload(?string $content): ?array
    {
        $trimmed = self::normalizeReportContent($content);
        if ($trimmed === '') {
            return null;
        }

        if (! str_starts_with($trimmed, '{') && ! str_starts_with($trimmed, '[')) {
            return null;
        }

        $decoded = json_decode($trimmed, true);

        return is_array($decoded) ? $decoded : null;
    }

    protected static function normalizeReportContent(?string $content): string
    {
        $trimmed = trim((string) $content);
        if ($trimmed === '') {
            return '';
        }

        if (preg_match('/^```(?:json)?\s*([\s\S]*?)\s*```$/i', $trimmed, $match)) {
            return trim($match[1]);
        }

        return $trimmed;
    }

  /**
     * @return array{owner: ?string, email: ?string, phone: ?string, social_media: ?string, website: ?string, address: ?string, location: ?string, processor: ?string, pos_system: ?string}
     */
    protected static function build(WorkflowLead $lead): array
    {
        $parsed = [];
        if (filled($lead->markdown_report)) {
            $parsed = app(MarkdownReportParser::class)->parseContent($lead->markdown_report);
        }

        $rawRow = is_array($lead->raw_row) ? $lead->raw_row : [];

        $rawWebsite = self::firstAvailable(
            $lead->website,
            $parsed['website'] ?? null,
            self::rawValue($rawRow, ['website', 'url', 'domain', 'web']),
            self::rawValueFuzzy($rawRow, ['website', 'url', 'domain']),
        );

        $rawSocial = self::firstAvailable(
            self::rawValue($rawRow, ['social_media', 'social', 'facebook', 'instagram', 'twitter', 'linkedin', 'tiktok', 'youtube']),
            self::rawValueFuzzy($rawRow, ['social media', 'social', 'facebook', 'instagram', 'twitter', 'linkedin', 'tiktok', 'youtube']),
        );

        [$website, $socialMedia] = self::splitWebsiteAndSocial($rawWebsite, $rawSocial);

        return [
            'owner' => self::resolveOwner(
                $lead->owner_name,
                $parsed['owner_name'] ?? null,
                $rawRow,
            ),
            'email' => self::firstAvailable(
                $lead->direct_email,
                $lead->input_email,
                $parsed['direct_email'] ?? null,
                self::rawValue($rawRow, ['email', 'input_email', 'owner_email', 'direct_email', 'e-mail']),
                self::rawValueFuzzy($rawRow, ['email', 'e-mail']),
            ),
            'phone' => self::firstAvailable(
                $lead->direct_phone,
                $lead->input_phone,
                $parsed['direct_phone'] ?? null,
                self::rawValue($rawRow, ['phone', 'input_phone', 'phone_number', 'direct_phone', 'mobile', 'telephone', 'contact number', 'contact_number']),
                self::rawValueFuzzy($rawRow, ['phone', 'mobile', 'telephone', 'cell', 'contact number']),
            ),
            'social_media' => $socialMedia,
            'website' => $website,
            'address' => self::firstAvailable(
                $lead->address,
                $parsed['physical_address'] ?? null,
                self::rawValue($rawRow, ['address', 'street', 'physical_address']),
            ),
            'location' => self::location($lead, $parsed),
            'processor' => self::firstAvailable(
                $lead->payment_processor,
                $parsed['payment_processor'] ?? null,
                self::rawValue($rawRow, ['payment_processor', 'processor']),
            ),
            'pos_system' => self::firstAvailable(
                $lead->system_integration,
                $parsed['system_integration'] ?? null,
                self::rawValue($rawRow, ['booking_pos_software', 'pos_system', 'system_integration']),
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    protected static function location(WorkflowLead $lead, array $parsed): ?string
    {
        $cityState = trim(implode(', ', array_filter([$lead->city, $lead->state])), ', ');
        if ($cityState !== '') {
            return $cityState;
        }

        $address = self::firstAvailable(
            $lead->address,
            $parsed['physical_address'] ?? null,
        );

        if ($address && preg_match('/,\s*([A-Za-z .\'-]+),\s*([A-Z]{2})\s*\d{5}/', $address, $match)) {
            return trim($match[1].', '.$match[2]);
        }

        return $address;
    }

    /**
     * Prefer a real person/owner name. Never treat contact-number / phone cells as "owner".
     *
     * @param  array<string, mixed>  $rawRow
     */
    protected static function resolveOwner(mixed ...$candidatesAndRow): ?string
    {
        $rawRow = [];
        $candidates = [];
        foreach ($candidatesAndRow as $item) {
            if (is_array($item)) {
                $rawRow = $item;
                continue;
            }
            $candidates[] = $item;
        }

        foreach ($candidates as $candidate) {
            $normalized = self::normalizeOwnerName($candidate);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return self::ownerFromRawRow($rawRow);
    }

    /**
     * @param  array<string, mixed>  $rawRow
     */
    protected static function ownerFromRawRow(array $rawRow): ?string
    {
        if ($rawRow === []) {
            return null;
        }

        $exactKeys = [
            'owner_name',
            'owner',
            'contact_name',
            'primary_contact',
            'proprietor',
            'decision_maker',
            'decision makers',
        ];
        foreach ($exactKeys as $key) {
            if (! array_key_exists($key, $rawRow)) {
                continue;
            }
            $normalized = self::normalizeOwnerName($rawRow[$key]);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        $bestScore = 0;
        $bestValue = null;
        foreach ($rawRow as $header => $value) {
            $headerNorm = self::normalizeHeader((string) $header);
            if ($headerNorm === '' || self::headerLooksLikePhoneOrEmail($headerNorm)) {
                continue;
            }

            $score = 0;
            if (str_contains($headerNorm, 'owner')) {
                $score += 100;
            }
            if (str_contains($headerNorm, 'decision maker')) {
                $score += 90;
            }
            if (str_contains($headerNorm, 'proprietor')) {
                $score += 80;
            }
            if (str_contains($headerNorm, 'contact name') || str_contains($headerNorm, 'primary contact')) {
                $score += 75;
            }
            if (str_contains($headerNorm, 'management') || str_contains($headerNorm, 'operations')) {
                $score += 40;
            }
            // Bare "manager" only if it is not a phone/contact header.
            if (preg_match('/\bmanager\b/', $headerNorm) === 1 && $score === 0) {
                $score += 35;
            }

            if ($score <= 0) {
                continue;
            }

            $normalized = self::normalizeOwnerName($value);
            if ($normalized === null) {
                continue;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestValue = $normalized;
            }
        }

        return $bestValue;
    }

    protected static function headerLooksLikePhoneOrEmail(string $normalizedHeader): bool
    {
        if (str_contains($normalizedHeader, 'email') || str_contains($normalizedHeader, 'e mail')) {
            return true;
        }

        // "Contact Number" / "Phone" / "Mobile" must never feed the Owner column.
        foreach (['contact number', 'phone', 'mobile', 'telephone', 'cell', 'fax', 'whatsapp'] as $needle) {
            if (str_contains($normalizedHeader, $needle)) {
                return true;
            }
        }

        // Bare "contact" without "name" is usually a phone/contact channel column.
        if (str_contains($normalizedHeader, 'contact') && ! str_contains($normalizedHeader, 'name')) {
            return true;
        }

        return false;
    }

    protected static function normalizeOwnerName(mixed $value): ?string
    {
        $normalized = self::normalizeScalar($value);
        if ($normalized === null) {
            return null;
        }

        if (self::looksLikePhoneNumber($normalized)) {
            return null;
        }

        if (self::looksLikeEmail($normalized)) {
            return null;
        }

        return $normalized;
    }

    public static function looksLikePhoneNumber(string $value): bool
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (strlen($digits) < 10 || strlen($digits) > 15) {
            return false;
        }

        // Mostly digits / phone punctuation → treat as phone, not a person name.
        $stripped = preg_replace('/[\d\s\-()+.extEXT#]+/', '', $value) ?? '';

        return trim($stripped) === '';
    }

    protected static function looksLikeEmail(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $keys
     */
    protected static function rawValue(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $row)) {
                continue;
            }

            $normalized = self::normalizeScalar($row[$key]);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $needles
     */
    protected static function rawValueFuzzy(array $row, array $needles): ?string
    {
        foreach ($row as $header => $value) {
            $normalizedHeader = self::normalizeHeader((string) $header);
            if ($normalizedHeader === '') {
                continue;
            }

            foreach ($needles as $needle) {
                if (! str_contains($normalizedHeader, self::normalizeHeader($needle))) {
                    continue;
                }

                $normalized = self::normalizeScalar($value);
                if ($normalized !== null) {
                    return $normalized;
                }
            }
        }

        return null;
    }

    protected static function normalizeHeader(string $header): string
    {
        $header = strtolower(trim($header));
        $header = str_replace(['_', '-', '/', '\\', '|'], ' ', $header);

        return preg_replace('/\s+/', ' ', $header) ?? $header;
    }

    /**
     * @return array{0: ?string, 1: ?string} [website, social_media]
     */
    protected static function splitWebsiteAndSocial(?string $website, ?string $social): array
    {
        $resolvedSocial = $social;
        $resolvedWebsite = null;

        if (filled($website)) {
            if (self::isSocialMediaUrl($website)) {
                $resolvedSocial = self::firstAvailable($resolvedSocial, $website);
            } else {
                $resolvedWebsite = $website;
            }
        }

        return [$resolvedWebsite, $resolvedSocial];
    }

    protected static function isSocialMediaUrl(?string $value): bool
    {
        if (! filled($value)) {
            return false;
        }

        $host = self::extractHost((string) $value);
        if ($host === '') {
            return false;
        }

        foreach (self::socialMediaHosts() as $socialHost) {
            if ($host === $socialHost || str_ends_with($host, '.'.$socialHost)) {
                return true;
            }
        }

        return false;
    }

    protected static function extractHost(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return '';
        }

        if (! str_contains($value, '://')) {
            $value = 'https://'.$value;
        }

        $host = parse_url($value, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return strtolower(preg_replace('/^www\./', '', trim($value))) ?? '';
        }

        return preg_replace('/^www\./', '', strtolower($host)) ?? strtolower($host);
    }

    /**
     * @return list<string>
     */
    protected static function socialMediaHosts(): array
    {
        return [
            'facebook.com',
            'fb.com',
            'instagram.com',
            'twitter.com',
            'x.com',
            'linkedin.com',
            'tiktok.com',
            'youtube.com',
            'youtu.be',
            'yelp.com',
            'pinterest.com',
            'snapchat.com',
            'threads.net',
        ];
    }

    protected static function firstAvailable(mixed ...$candidates): ?string
    {
        foreach ($candidates as $value) {
            $normalized = self::normalizeScalar($value);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    protected static function normalizeScalar(mixed $value): ?string
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                $normalized = self::normalizeScalar($item);
                if ($normalized !== null) {
                    return $normalized;
                }
            }

            return null;
        }

        if (! is_scalar($value)) {
            return null;
        }

        if (self::isUnavailable($value)) {
            return null;
        }

        return trim((string) $value);
    }

    public static function isUnavailable(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if (! self::isUnavailable($item)) {
                    return false;
                }
            }

            return true;
        }

        if (! is_scalar($value)) {
            return true;
        }

        $value = trim((string) $value);
        $value = \App\Support\SpreadsheetText::repairMojibake($value);

        if ($value === '' || $value === '—' || $value === '–' || $value === '-') {
            return true;
        }

        return in_array(strtolower($value), [
            'not publicly available',
            'none found',
            'n/a',
            'na',
            'none',
            'unknown',
            'unavailable',
            'γçö',
            'гçö',
        ], true);
    }

    public static function label(?string $value, string $fallback = '—'): string
    {
        if (self::isUnavailable($value)) {
            return $fallback;
        }

        return \App\Support\SpreadsheetText::normalize((string) $value);
    }

    public static function cell(?string $value): string
    {
        if (self::isUnavailable($value)) {
            return '';
        }

        return \App\Support\SpreadsheetText::normalize((string) $value);
    }
}
