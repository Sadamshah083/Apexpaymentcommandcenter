<?php

namespace App\Services\Communications;

class ZoomClickToCallService
{
    public function publicSipHost(): string
    {
        $sipHost = trim((string) config('integrations.morpheus.sip_host', ''));
        $host = trim((string) config('integrations.morpheus.host', ''));

        if ($sipHost === '') {
            return $host;
        }

        if (str_ends_with(strtolower($sipHost), '.local') && $host !== '') {
            return $host;
        }

        return $sipHost;
    }

    /**
     * SIP realm for WebRTC REGISTER (e.g. apexone.pbx.local). WSS stays on the public Morpheus host.
     */
    public function webrtcSipDomain(): string
    {
        $configured = trim((string) config('integrations.morpheus.webrtc_sip_domain', ''));
        if ($configured !== '') {
            return $configured;
        }

        $sipHost = trim((string) config('integrations.morpheus.sip_host', ''));
        if ($sipHost !== '' && str_ends_with(strtolower($sipHost), '.pbx.local')) {
            return $sipHost;
        }

        $host = trim((string) config('integrations.morpheus.host', ''));
        if ($host !== '' && str_contains($host, '.')) {
            $subdomain = explode('.', $host)[0];
            if ($subdomain !== '') {
                return $subdomain.'.pbx.local';
            }
        }

        return $this->publicSipHost();
    }

    public function publicWssHost(): string
    {
        return $this->publicSipHost();
    }

    /**
     * Direct Morpheus WebSocket for browser SIP (REGISTER + INVITE/BYE).
     * Must match the Morpheus agent portal: wss://apexone.morpheus.cx:7443/ with subprotocol sip.
     */
    public function defaultSipWssUrl(): string
    {
        return 'wss://apexone.morpheus.cx:7443/';
    }

    public function resolveSipWssUrl(): string
    {
        $configured = trim((string) config('integrations.morpheus.sip_wss_url', ''));
        if ($configured !== '') {
            return rtrim($configured, '/').'/';
        }

        $host = $this->publicWssHost();
        if ($host !== '') {
            return "wss://{$host}:7443/";
        }

        return $this->defaultSipWssUrl();
    }

    public function normalizePhone(string $phone): string
    {
        $phone = trim($phone);

        // Lead exports often use carrier prefixes like 482983#12699086765
        if (str_contains($phone, '#')) {
            $phone = trim(substr($phone, strrpos($phone, '#') + 1));
        }

        $digits = preg_replace('/[^\d+]/', '', $phone) ?? '';

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

    /**
     * Morpheus contactivity trunk expects tech-prefixed dial strings (e.g. 482983#12722001232).
     * Extensions (<=6 digits) are never prefixed.
     */
    public function formatOriginateDestination(string $phone): string
    {
        $phone = trim($phone);

        if (preg_match('/^\d+#\d+$/', preg_replace('/[^\d#]/', '', $phone) ?? '')) {
            return preg_replace('/[^\d#]/', '', $phone) ?? '';
        }

        if (str_contains($phone, '#')) {
            $phone = trim(substr($phone, strrpos($phone, '#') + 1));
        }

        $digits = preg_replace('/\D/', '', $phone) ?? '';

        if ($digits === '' || strlen($digits) <= 6) {
            return $digits;
        }

        $techPrefix = trim((string) config('integrations.morpheus.outbound_prefix', ''));
        if ($techPrefix === '') {
            return $digits;
        }

        return rtrim($techPrefix, '#').'#'.$digits;
    }

    public function isExtension(string $phone): bool
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        return $digits !== '' && strlen($digits) <= 6;
    }

    public function isValidPstnDestination(string $phone): bool
    {
        $normalized = $this->normalizePhone($phone);
        if ($normalized === '' || $this->isExtension($normalized)) {
            return false;
        }

        $digits = preg_replace('/\D/', '', $normalized) ?? '';

        return strlen($digits) >= 10;
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
        $host = $this->publicSipHost();
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
