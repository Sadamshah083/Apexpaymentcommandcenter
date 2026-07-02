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

        if (strlen($numeric) <= 6) {
            return $numeric;
        }

        return '+'.$numeric;
    }

    public function isExtension(string $phone): bool
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        return $digits !== '' && strlen($digits) <= 6;
    }

    public function dialUrl(string $phoneNumber, ?string $callerId = null): ?string
    {
        return $this->preferredDialUrl($phoneNumber, $callerId);
    }

    public function preferredDialUrl(string $phoneNumber, ?string $fromExtension = null): ?string
    {
        $method = (string) config('integrations.morpheus.dial_method', 'api');

        if ($method === 'tel') {
            return $this->telUrl($phoneNumber);
        }

        if ($method === 'sip') {
            return $this->sipUrl($phoneNumber, $fromExtension);
        }

        return $this->sipUrl($phoneNumber, $fromExtension) ?? $this->telUrl($phoneNumber);
    }

    public function telUrl(string $phoneNumber): ?string
    {
        $target = $this->normalizePhone($phoneNumber);
        if ($target === '') {
            return null;
        }

        return 'tel:'.$target;
    }

    public function sipUrl(string $phoneNumber, ?string $fromExtension = null): ?string
    {
        $host = (string) (config('integrations.morpheus.sip_host') ?: config('integrations.morpheus.host'));
        if ($host === '') {
            return null;
        }

        $target = $this->normalizePhone($phoneNumber);
        if ($target === '') {
            return null;
        }

        $prefix = (string) config('integrations.morpheus.outbound_prefix', '');
        $dialString = $prefix.$target;

        if ($this->isExtension($dialString)) {
            return 'sip:'.$dialString.'@'.$host;
        }

        $sipUser = ltrim($dialString, '+');
        $params = (string) config('integrations.morpheus.sip_params', 'user=phone');

        return 'sip:'.$sipUser.'@'.$host.($params !== '' ? ';'.$params : '');
    }

    public function portalUrl(): string
    {
        $configured = (string) config('integrations.morpheus.portal_url', '');
        if ($configured !== '') {
            return $configured;
        }

        $host = (string) config('integrations.morpheus.host', '');

        return $host !== '' ? 'https://'.$host.'/' : '#';
    }
}
