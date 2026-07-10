<?php

namespace App\Support;

class UsPhoneNormalizer
{
    /**
     * Normalize a US phone to 11 digits (1 + 10-digit NANP) or null if invalid/empty.
     */
    public static function normalize(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if ($digits === '') {
            return null;
        }

        if (strlen($digits) === 10) {
            return '1'.$digits;
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return $digits;
        }

        return null;
    }

    /**
     * Compact E.164 display: +15551234567
     */
    public static function e164(?string $value): ?string
    {
        $normalized = self::normalize($value);

        return $normalized ? '+'.$normalized : null;
    }

    /**
     * Display format: +1 (555) 123-4567
     */
    public static function format(?string $normalized): ?string
    {
        if (! $normalized || strlen($normalized) !== 11 || ! str_starts_with($normalized, '1')) {
            return null;
        }

        $area = substr($normalized, 1, 3);
        $prefix = substr($normalized, 4, 3);
        $line = substr($normalized, 7, 4);

        return "+1 ({$area}) {$prefix}-{$line}";
    }
}
