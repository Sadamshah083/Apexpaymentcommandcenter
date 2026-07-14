<?php

namespace App\Support;

/**
 * Detect the real header row in messy CRM exports (title/niche preamble rows, blank rows).
 */
class SpreadsheetHeaderDetector
{
    /**
     * Known header tokens that indicate a column-label row.
     *
     * @var array<int, string>
     */
    protected const HEADER_TOKENS = [
        'city',
        'business name',
        'company name',
        'business',
        'company',
        'contact number',
        'phone',
        'phone number',
        'mobile',
        'telephone',
        'address',
        'street',
        'owner name',
        'owner',
        'contact name',
        'email',
        'website',
        'url',
        'state',
        'zip',
        'zip code',
        'postal code',
        'country',
        'first name',
        'last name',
        'full name',
    ];

    /**
     * Values that look like a metadata / title preamble instead of headers.
     *
     * @var array<int, string>
     */
    protected const PREAMBLE_MARKERS = [
        'niche:',
        'state:',
        'city:',
        'list:',
        'campaign:',
        'export:',
        'report:',
        'generated:',
        'filter:',
    ];

    /**
     * @param  array<int, array<int, mixed>>  $rows  Raw sheet rows (0-based).
     * @return array{index: int, headers: array<int, string>}
     */
    public static function detect(array $rows, int $scanLimit = 25): array
    {
        if ($rows === []) {
            return ['index' => 0, 'headers' => []];
        }

        $limit = min(count($rows), max(1, $scanLimit));
        $bestIndex = 0;
        $bestScore = PHP_INT_MIN;
        $bestHeaders = SpreadsheetText::normalizeRow($rows[0] ?? []);

        for ($i = 0; $i < $limit; $i++) {
            $headers = SpreadsheetText::normalizeRow($rows[$i] ?? []);
            $score = self::scoreHeaderRow($headers);

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestIndex = $i;
                $bestHeaders = $headers;
            }
        }

        // Prefer a clearly labeled header row; otherwise fall back to the first non-empty row.
        if ($bestScore < 40) {
            foreach ($rows as $i => $row) {
                $headers = SpreadsheetText::normalizeRow($row);
                if (self::nonEmptyCount($headers) >= 2 && ! self::looksLikePreamble($headers)) {
                    return ['index' => $i, 'headers' => $headers];
                }
            }
        }

        return ['index' => $bestIndex, 'headers' => $bestHeaders];
    }

    /**
     * @param  array<int, string>  $headers
     */
    public static function scoreHeaderRow(array $headers): int
    {
        $nonEmpty = array_values(array_filter($headers, fn ($h) => $h !== ''));
        if ($nonEmpty === []) {
            return -1000;
        }

        if (self::looksLikePreamble($headers)) {
            return -500;
        }

        $score = 0;
        $tokenHits = 0;

        foreach ($nonEmpty as $header) {
            $normalized = self::normalizeHeader($header);

            // Header cells are usually short labels, not full sentences / addresses.
            if (mb_strlen($header) > 60) {
                $score -= 25;
            }

            if (preg_match('/^\+?\d[\d\s\-().]{6,}$/', $header) === 1) {
                $score -= 40; // phone-looking "header"
            }

            foreach (self::HEADER_TOKENS as $token) {
                if ($normalized === $token || str_contains($normalized, $token)) {
                    $score += 35;
                    $tokenHits++;
                    break;
                }
            }
        }

        $score += min(count($nonEmpty), 8) * 5;

        // Strong preference when multiple classic CRM headers appear together.
        if ($tokenHits >= 3) {
            $score += 80;
        } elseif ($tokenHits === 2) {
            $score += 40;
        }

        return $score;
    }

    /**
     * @param  array<int, string>  $headers
     */
    public static function looksLikePreamble(array $headers): bool
    {
        $joined = self::normalizeHeader(implode(' ', array_filter($headers)));
        if ($joined === '') {
            return false;
        }

        foreach (self::PREAMBLE_MARKERS as $marker) {
            if (str_starts_with($joined, $marker) || str_contains($joined, ' '.$marker)) {
                return true;
            }
        }

        // Single fat label cell like "Niche: Auto Repair State: GA"
        $nonEmpty = array_values(array_filter($headers, fn ($h) => $h !== ''));
        if (count($nonEmpty) <= 2 && preg_match('/\b(niche|state|city|campaign|list)\s*:/i', $joined) === 1) {
            return true;
        }

        return false;
    }

    protected static function normalizeHeader(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[\s_\-]+/', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * @param  array<int, string>  $headers
     */
    protected static function nonEmptyCount(array $headers): int
    {
        return count(array_filter($headers, fn ($h) => $h !== ''));
    }
}
