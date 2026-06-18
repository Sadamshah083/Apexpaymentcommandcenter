<?php

namespace App\Services\Communications;

class ZoomClickToCallService
{
    public function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/[^\d+]/', '', trim($phone)) ?? '';

        if ($digits === '') {
            return '';
        }

        if (str_starts_with($digits, '+')) {
            return $digits;
        }

        $numeric = ltrim($digits, '0');

        if (strlen($numeric) === 10) {
            return '+1'.$numeric;
        }

        return '+'.$numeric;
    }

    public function dialUrl(string $phoneNumber, ?string $callerId = null): ?string
    {
        $target = $this->normalizePhone($phoneNumber);
        if ($target === '') {
            return null;
        }

        $url = 'zoomphonecall://'.$target;

        $caller = filled($callerId) ? $this->normalizePhone($callerId) : '';
        if ($caller !== '') {
            $url .= '?callerid='.rawurlencode($caller);
        }

        return $url;
    }

    public function telUrl(string $phoneNumber): ?string
    {
        $target = $this->normalizePhone($phoneNumber);
        if ($target === '') {
            return null;
        }

        return 'tel:'.$target;
    }
}
