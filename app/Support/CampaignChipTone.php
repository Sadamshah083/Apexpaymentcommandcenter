<?php

namespace App\Support;

class CampaignChipTone
{
    /** @var list<string> */
    public const TONES = [
        'emerald',
        'sky',
        'violet',
        'amber',
        'rose',
        'cyan',
        'indigo',
        'teal',
        'orange',
        'lime',
        'fuchsia',
        'blue',
    ];

    public static function for(int|string|null $campaignId, ?string $campaignName = null): string
    {
        $id = (int) $campaignId;
        if ($id > 0) {
            return self::TONES[$id % count(self::TONES)];
        }

        $raw = trim((string) $campaignName);
        if ($raw === '') {
            return 'slate';
        }

        $hash = crc32(mb_strtolower($raw));
        $index = (int) sprintf('%u', $hash) % count(self::TONES);

        return self::TONES[$index];
    }

    public static function className(int|string|null $campaignId, ?string $campaignName = null, bool $compact = false): string
    {
        $tone = self::for($campaignId, $campaignName);
        $classes = ['campaign-chip', 'campaign-chip--'.$tone];
        if ($compact) {
            $classes[] = 'campaign-chip--sm';
        }

        return implode(' ', $classes);
    }
}
