<?php

namespace App\Support;

/**
 * FreeSWITCH/Morpheus-safe SIP identity fields for REGISTER and originate.
 *
 * Uses a consistent business CNAM (not raw digits or internal CRM usernames) to reduce spam labeling.
 */
final class MorpheusSipIdentity
{
    public static function defaultCallerIdName(): string
    {
        $configured = trim((string) config('integrations.communications.default_caller_id_name', ''));

        return $configured !== '' ? $configured : 'ApexOne Payments';
    }

    /**
     * Caller ID name for SIP originate / CNAM presentation.
     */
    public static function displayName(?string $candidate, ?string $callerIdNumber = null): string
    {
        unset($callerIdNumber);

        $candidate = trim((string) $candidate);
        if ($candidate !== '' && self::isAcceptableCallerIdName($candidate)) {
            return $candidate;
        }

        return self::defaultCallerIdName();
    }

    public static function isAcceptableCallerIdName(string $value): bool
    {
        if ($value === '' || self::isInternalUsername($value)) {
            return false;
        }

        if (self::isDigitOnlyName($value) || self::isExtensionLabel($value)) {
            return false;
        }

        return ! preg_match('/[<>"\\\\;]/', $value);
    }

    public static function isDigitOnlyName(string $value): bool
    {
        $digits = preg_replace('/\D/', '', $value) ?? '';

        return $digits !== '' && strlen($digits) >= 10 && preg_match('/^\d[\d\s().+-]*$/', $value);
    }

    public static function isExtensionLabel(string $value): bool
    {
        return (bool) preg_match('/^(billing\s+)?ext(ension)?\s*\d+$/i', $value);
    }

    public static function isInternalUsername(string $value): bool
    {
        return (bool) preg_match(
            '/^(admin|setter|closer)_(super|ops|tl|ag)_[a-z0-9]{3}$/i',
            $value,
        );
    }

    /**
     * Morpheus sometimes dials a browser SIP contact hash instead of PSTN (e.g. 2c7sd3fg).
     */
    public static function isSipContactHash(string $value): bool
    {
        $value = strtolower(trim($value));

        if ($value === '' || ctype_digit($value)) {
            return false;
        }

        return strlen($value) <= 12 && (bool) preg_match('/^[a-z0-9]+$/', $value);
    }
}
