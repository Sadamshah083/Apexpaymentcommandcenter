<?php

namespace App\Support;

/**
 * Normalize spreadsheet/CSV cell text to clean UTF-8 and repair common mojibake.
 */
class SpreadsheetText
{
    /**
     * Mojibake sequences commonly produced when UTF-8 punctuation is mis-decoded.
     *
     * @var array<string, string>
     */
    protected const MOJIBAKE_MAP = [
        'ΓÇö' => '—', // em dash
        'Гçö' => '—',
        'ΓÇô' => '–', // en dash
        'Гçô' => '–',
        'ΓÇÖ' => "'", // right single quote
        'ΓÇÿ' => "'", // left single quote
        'ΓÇ£' => '"', // left double quote
        'ΓÇ¥' => '"', // right double quote
        'ΓÇª' => '…',
        'â€"' => '—',
        'â€“' => '–',
        'â€™' => "'",
        'â€˜' => "'",
        'â€œ' => '"',
        'â€' => '"',
        'â€¦' => '…',
        'Â ' => ' ', // non-breaking space
        "\u{00A0}" => ' ',
    ];

    public static function normalize(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            if (is_float($value) && floor($value) == $value) {
                return (string) (int) $value;
            }

            return (string) $value;
        }

        $text = (string) $value;
        if ($text === '') {
            return '';
        }

        $text = self::stripBom($text);
        $text = self::ensureUtf8($text);
        $text = self::repairMojibake($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Collapse invisible control chars except tab/newline.
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? $text;

        return trim($text);
    }

    /**
     * @param  array<int, mixed>  $row
     * @return array<int, string>
     */
    public static function normalizeRow(array $row): array
    {
        return array_map(fn ($cell) => self::normalize($cell), $row);
    }

    public static function stripBom(string $text): string
    {
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            return substr($text, 3);
        }

        if (str_starts_with($text, "\xFF\xFE") || str_starts_with($text, "\xFE\xFF")) {
            $converted = @mb_convert_encoding($text, 'UTF-8', 'UTF-16');
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }

        return $text;
    }

    public static function ensureUtf8(string $text): string
    {
        if ($text === '') {
            return '';
        }

        if (mb_check_encoding($text, 'UTF-8') && ! self::looksLikeLatin1Mojibake($text)) {
            return $text;
        }

        foreach (['Windows-1252', 'ISO-8859-1', 'CP1251'] as $from) {
            $converted = @mb_convert_encoding($text, 'UTF-8', $from);
            if (is_string($converted) && $converted !== '' && mb_check_encoding($converted, 'UTF-8')) {
                return $converted;
            }
        }

        $ignored = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
        if (is_string($ignored)) {
            return $ignored;
        }

        return $text;
    }

    public static function repairMojibake(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $repaired = strtr($text, self::MOJIBAKE_MAP);

        // Double-encoded UTF-8 (e.g. em dash became "Ã¢â‚¬â€").
        if (preg_match('/Ã.|Â.|Ð.|Ñ./u', $repaired) === 1) {
            $again = @mb_convert_encoding($repaired, 'UTF-8', 'Windows-1252');
            if (is_string($again) && $again !== '' && ! str_contains($again, 'Ã')) {
                $repaired = strtr($again, self::MOJIBAKE_MAP);
            }
        }

        return $repaired;
    }

    protected static function looksLikeLatin1Mojibake(string $text): bool
    {
        return (bool) preg_match('/Ã[\x80-\xBF]|Â[\xA0-\xBF]|â€/u', $text);
    }
}
