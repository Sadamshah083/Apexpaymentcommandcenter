<?php

namespace App\Support;

/**
 * FreeSWITCH/Morpheus-safe SIP identity fields for REGISTER and originate.
 */
final class MorpheusSipIdentity
{
    /**
     * Caller ID name for SIP — use outbound DID digits or empty (never internal CRM usernames).
     */
    public static function displayName(?string $candidate, ?string $callerIdNumber = null): string
    {
        $digits = preg_replace('/\D/', '', (string) $callerIdNumber) ?? '';
        if ($digits !== '') {
            return $digits;
        }

        $candidate = trim((string) $candidate);
        if ($candidate === '' || self::isInternalUsername($candidate)) {
            return '';
        }

        if (preg_match('/[<>"\\\\;]/', $candidate)) {
            return '';
        }

        return $candidate;
    }

    public static function isInternalUsername(string $value): bool
    {
        return (bool) preg_match(
            '/^(admin|setter|closer)_(super|ops|tl|ag)_[a-z0-9]{3}$/i',
            $value,
        );
    }
}
