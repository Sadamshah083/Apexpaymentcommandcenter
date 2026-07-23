<?php

namespace App\Support;

/**
 * Detect the real header row in messy CRM exports (title/niche preamble rows, blank rows).
 * Also invents synthetic headers for headerless lead dumps (data starts on row 1).
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
     * @var array<int, string>
     */
    protected const PHONE_TYPE_VALUES = [
        'mobile',
        'fixed line',
        'fixedline',
        'landline',
        'voip',
        'cell',
        'cellular',
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

        // Headerless dumps: first rows are lead data (phones, Mr./Ms., company names).
        if ($bestScore < 40 || self::looksLikeDataRow($bestHeaders)) {
            $inferred = self::inferHeadersFromContent($rows);
            if ($inferred !== null) {
                return $inferred;
            }

            foreach ($rows as $i => $row) {
                $headers = SpreadsheetText::normalizeRow($row);
                if (self::nonEmptyCount($headers) >= 2
                    && ! self::looksLikePreamble($headers)
                    && ! self::looksLikeDataRow($headers)
                ) {
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

        if (self::looksLikeDataRow($headers)) {
            return -200;
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

            if (self::looksLikePersonNameCell($header) || self::isPhoneTypeValue($normalized)) {
                $score -= 45;

                continue;
            }

            foreach (self::HEADER_TOKENS as $token) {
                if ($normalized === $token || str_contains($normalized, $token)) {
                    // Short tokens like "owner"/"mobile" must be label-sized, not buried in prose.
                    if (mb_strlen($normalized) > 36 && $normalized !== $token) {
                        continue;
                    }
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

    /**
     * True when a candidate "header" row is actually the first data row.
     *
     * @param  array<int, string>  $headers
     */
    public static function looksLikeDataRow(array $headers): bool
    {
        $nonEmpty = array_values(array_filter($headers, fn ($h) => $h !== ''));
        if (count($nonEmpty) < 2) {
            return false;
        }

        $dataSignals = 0;
        foreach ($nonEmpty as $cell) {
            $normalized = self::normalizeHeader($cell);
            if (self::looksLikePersonNameCell($cell)
                || self::isPhoneTypeValue($normalized)
                || preg_match('/^\+?\d[\d\s\-().]{6,}$/', $cell) === 1
                || filter_var($cell, FILTER_VALIDATE_EMAIL) !== false
                || preg_match('#^https?://#i', $cell) === 1
                || (preg_match('/^\d{1,6}$/', $cell) === 1 && mb_strlen($cell) <= 6)
            ) {
                $dataSignals++;
            }
        }

        return $dataSignals >= 2;
    }

    /**
     * Build synthetic CRM headers from column content when the sheet has no label row.
     *
     * @param  array<int, array<int, mixed>>  $rows
     * @return array{index: int, headers: array<int, string>}|null
     */
    public static function inferHeadersFromContent(array $rows): ?array
    {
        $sample = [];
        foreach (array_slice($rows, 0, 20) as $row) {
            $sample[] = SpreadsheetText::normalizeRow($row);
        }

        if ($sample === [] || ! self::looksLikeDataRow($sample[0] ?? [])) {
            return null;
        }

        $colCount = 0;
        foreach ($sample as $row) {
            $colCount = max($colCount, count($row));
        }
        if ($colCount < 2) {
            return null;
        }

        $scores = [];
        for ($c = 0; $c < $colCount; $c++) {
            $scores[$c] = [
                'phone' => 0,
                'email' => 0,
                'website' => 0,
                'owner' => 0,
                'phone_type' => 0,
                'business' => 0,
                'id' => 0,
                'filled' => 0,
            ];
        }

        foreach ($sample as $row) {
            for ($c = 0; $c < $colCount; $c++) {
                $cell = trim((string) ($row[$c] ?? ''));
                if ($cell === '') {
                    continue;
                }
                $scores[$c]['filled']++;
                $normalized = self::normalizeHeader($cell);

                if (self::isPhoneTypeValue($normalized)) {
                    $scores[$c]['phone_type'] += 3;
                    continue;
                }
                if (preg_match('/^\+?\d[\d\s\-().]{6,}$/', $cell) === 1
                    || LeadContactDisplay::looksLikePhoneNumber($cell)
                ) {
                    $scores[$c]['phone'] += 3;
                    continue;
                }
                if (filter_var($cell, FILTER_VALIDATE_EMAIL) !== false) {
                    $scores[$c]['email'] += 3;
                    continue;
                }
                if (preg_match('#^https?://#i', $cell) === 1 || preg_match('/\.[a-z]{2,}(\/|$)/i', $cell) === 1) {
                    $scores[$c]['website'] += 3;
                    continue;
                }
                if (self::looksLikePersonNameCell($cell)) {
                    $scores[$c]['owner'] += 3;
                    continue;
                }
                if (preg_match('/^\d{1,6}$/', $cell) === 1) {
                    $scores[$c]['id'] += 2;
                    continue;
                }
                if (mb_strlen($cell) >= 3 && preg_match('/[a-zA-Z]/', $cell) === 1) {
                    $scores[$c]['business'] += min(4, (int) floor(mb_strlen($cell) / 8) + 1);
                }
            }
        }

        $used = [];
        $pick = function (string $metric, int $minScore = 2) use (&$scores, &$used): ?int {
            $best = null;
            $bestScore = $minScore - 1;
            foreach ($scores as $col => $bag) {
                if (isset($used[$col])) {
                    continue;
                }
                $value = (int) ($bag[$metric] ?? 0);
                if ($value > $bestScore) {
                    $bestScore = $value;
                    $best = $col;
                }
            }
            if ($best === null) {
                return null;
            }
            $used[$best] = true;

            return $best;
        };

        $roles = [
            'Contact Number' => $pick('phone', 2),
            'Owner Name' => $pick('owner', 2),
            'Email' => $pick('email', 2),
            'Website' => $pick('website', 2),
            'Business Name' => $pick('business', 2),
            'Phone Type' => $pick('phone_type', 2),
            'ID' => $pick('id', 3),
        ];

        // Business name is required for import — fall back to the fullest unused text column.
        if ($roles['Business Name'] === null) {
            $fallback = null;
            $fallbackFilled = 0;
            foreach ($scores as $col => $bag) {
                if (isset($used[$col])) {
                    continue;
                }
                if ((int) $bag['filled'] > $fallbackFilled && (int) $bag['business'] > 0) {
                    $fallbackFilled = (int) $bag['filled'];
                    $fallback = $col;
                }
            }
            if ($fallback !== null) {
                $roles['Business Name'] = $fallback;
                $used[$fallback] = true;
            }
        }

        if ($roles['Business Name'] === null) {
            return null;
        }

        $headers = array_fill(0, $colCount, '');
        foreach ($roles as $label => $col) {
            if ($col !== null) {
                $headers[$col] = $label;
            }
        }
        for ($c = 0; $c < $colCount; $c++) {
            if ($headers[$c] === '' && ($scores[$c]['filled'] ?? 0) > 0) {
                $headers[$c] = 'Column '.($c + 1);
            }
        }

        // index -1 ⇒ ProcessWorkflowJob / mappers treat every row as data.
        return ['index' => -1, 'headers' => $headers];
    }

    protected static function looksLikePersonNameCell(string $value): bool
    {
        if (preg_match('/\b(mr|mrs|ms|miss)\.?\s+/i', $value) === 1) {
            return true;
        }

        return preg_match('/\((owner|founder|president|ceo|manager|director)\)/i', $value) === 1;
    }

    protected static function isPhoneTypeValue(string $normalized): bool
    {
        return in_array($normalized, self::PHONE_TYPE_VALUES, true);
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
