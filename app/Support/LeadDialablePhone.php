<?php

namespace App\Support;

use App\Models\WorkflowLead;
use App\Services\BusinessResearch\MarkdownReportParser;

class LeadDialablePhone
{
    /**
     * Best dialable E.164 (+1XXXXXXXXXX) found on this lead, or null.
     */
    public static function resolve(?WorkflowLead $lead): ?string
    {
        if (! $lead) {
            return null;
        }

        $candidates = [];

        foreach ([
            $lead->normalized_phone,
            $lead->direct_phone,
            $lead->input_phone,
            LeadContactDisplay::value($lead, 'phone'),
        ] as $value) {
            if (is_string($value) && trim($value) !== '') {
                $candidates[] = $value;
            }
        }

        $raw = is_array($lead->raw_row) ? $lead->raw_row : [];
        foreach (['Contact No.', 'Contact No', 'phone', 'Phone', 'Phone Number', 'Mobile', 'Telephone', 'direct_phone', 'input_phone'] as $key) {
            if (! empty($raw[$key]) && is_scalar($raw[$key])) {
                $candidates[] = (string) $raw[$key];
            }
        }

        if (filled($lead->markdown_report)) {
            try {
                $parsed = app(MarkdownReportParser::class)->parseContent((string) $lead->markdown_report);
                foreach (($parsed['phones'] ?? []) as $phone) {
                    if (is_string($phone) && trim($phone) !== '') {
                        $candidates[] = $phone;
                    }
                }
                if (! empty($parsed['direct_phone'])) {
                    $candidates[] = (string) $parsed['direct_phone'];
                }
            } catch (\Throwable) {
                // Fall through to regex scrape.
            }

            if (preg_match_all('/(?:\+?1[-.\s]?)?\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}/', (string) $lead->markdown_report, $matches)) {
                foreach ($matches[0] as $match) {
                    $candidates[] = $match;
                }
            }
        }

        foreach ($candidates as $candidate) {
            if (self::isPlaceholder($candidate)) {
                continue;
            }
            $normalized = UsPhoneNormalizer::e164($candidate);
            if ($normalized) {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * Persist dialable phone onto lead columns when enrichment left placeholders.
     *
     * @return array{direct_phone?: string, normalized_phone?: string, input_phone?: string}
     */
    public static function syncAttributes(WorkflowLead $lead): array
    {
        $e164 = self::resolve($lead);
        if (! $e164) {
            return [];
        }

        $digits = UsPhoneNormalizer::normalize($e164);
        $updates = [];

        if (! self::hasPersistedDialablePhone($lead)) {
            $updates['normalized_phone'] = $digits;
            $updates['direct_phone'] = $e164;
            if (! filled($lead->input_phone) || self::isPlaceholder((string) $lead->input_phone)) {
                $updates['input_phone'] = $e164;
            }
        }

        return $updates;
    }

    public static function persist(WorkflowLead $lead): bool
    {
        $updates = self::syncAttributes($lead);
        if ($updates === []) {
            return false;
        }

        $lead->forceFill($updates)->save();

        return true;
    }

    public static function hasPersistedDialablePhone(WorkflowLead $lead): bool
    {
        foreach ([$lead->normalized_phone, $lead->direct_phone, $lead->input_phone] as $value) {
            if (! is_string($value) || self::isPlaceholder($value)) {
                continue;
            }
            if (UsPhoneNormalizer::normalize($value)) {
                return true;
            }
        }

        return false;
    }

    public static function isPlaceholder(?string $value): bool
    {
        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return true;
        }

        $lower = strtolower($trimmed);

        return str_contains($lower, 'not publicly')
            || str_contains($lower, 'n/a')
            || $lower === 'none'
            || $lower === 'null'
            || $lower === '-';
    }
}
