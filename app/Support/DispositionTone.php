<?php

namespace App\Support;

class DispositionTone
{
    /**
     * Known disposition → tone (matches Call Summary disposition popup).
     *
     * @var array<string, string>
     */
    public const MAP = [
        'answer machine' => 'amber',
        'answering machine' => 'amber',
        'call back' => 'indigo',
        'busy will call back' => 'indigo',
        'corporate business' => 'violet',
        'owner not available' => 'cyan',
        'wrong number/business' => 'orange',
        'wrong number' => 'orange',
        'owner hung up' => 'teal',
        'owner hang up' => 'teal',
        'owner hangup' => 'teal',
        'follow up' => 'sky',
        'not interested' => 'rose',
        'requested appointment' => 'emerald',
        'no answer' => 'slate',
        'gatekeeper' => 'amber',
        'dead call' => 'rose',
        'transferred' => 'indigo',
        'biz closed' => 'orange',
    ];

    /** @var list<string> */
    public const CUSTOM_TONES = [
        'amber',
        'orange',
        'sky',
        'emerald',
        'rose',
        'violet',
        'cyan',
        'indigo',
        'teal',
        'slate',
    ];

    public static function for(?string $label): string
    {
        $raw = trim((string) $label);
        if ($raw === '') {
            return 'slate';
        }

        $key = mb_strtolower($raw);
        if (isset(self::MAP[$key])) {
            return self::MAP[$key];
        }

        // Stable "random" tone for custom / free-typed dispositions.
        $hash = crc32(mb_strtolower($raw));
        $index = (int) sprintf('%u', $hash) % count(self::CUSTOM_TONES);

        return self::CUSTOM_TONES[$index];
    }
}
