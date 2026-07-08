<?php

namespace App\Services\Communications;

use App\Models\User;
use App\Models\Workspace;

class SaraPhoneService
{
    public function __construct(
        protected CommunicationsWebphoneService $webphone,
        protected ZoomClickToCallService $clickToCall,
    ) {}

    /**
     * SaraPhone preset (WSS + SIP domain + extension credentials).
     *
     * @return array<string, mixed>|null
     */
    public function presetFor(User $user, Workspace $workspace, string $extension, string $routePrefix): ?array
    {
        if (! (bool) config('integrations.morpheus.saraphone_enabled', true)) {
            return null;
        }

        $config = $this->webphone->configFor($user, $workspace, $extension, $routePrefix);
        if ($config === null) {
            return null;
        }

        $wssUrl = $this->clickToCall->resolveSipWssUrl();
        $parsed = parse_url($wssUrl);
        $wssHost = (string) ($parsed['host'] ?? $this->clickToCall->publicWssHost());
        $wssPort = (int) ($parsed['port'] ?? 7443);

        return [
            'sip_domain' => (string) ($config['domain'] ?? $this->clickToCall->webrtcSipDomain()),
            'dial_domain' => (string) ($config['dial_domain'] ?? $config['domain'] ?? $this->clickToCall->webrtcSipDomain()),
            'wss_host' => $wssHost,
            'wss_port' => $wssPort > 0 ? $wssPort : 7443,
            'wss_url' => $wssUrl,
            'extension' => (string) ($config['extension'] ?? $extension),
            'password' => (string) ($config['password'] ?? ''),
            'display_name' => 'Ext '.((string) ($config['extension'] ?? $extension)),
            'outbound_caller_id' => (string) ($config['outbound_caller_id'] ?? ''),
            'auto_login' => (bool) config('integrations.morpheus.saraphone_auto_login', true),
            'auto_answer' => (bool) ($config['auto_answer_click_to_call'] ?? true),
            'dial_method' => (string) ($config['dial_method'] ?? config('integrations.morpheus.dial_method', 'api')),
            'outbound_prefix' => (string) ($config['outbound_prefix'] ?? ''),
            'sip_params' => (string) ($config['sip_params'] ?? config('integrations.morpheus.sip_params', 'user=phone')),
            'originate_url' => route($routePrefix.'communications.morpheus.calls.originate'),
            'prepare_url' => route($routePrefix.'communications.morpheus.webphone.prepare'),
            'csrf_token' => csrf_token(),
        ];
    }

    /**
     * @return array{host: string, port: int, path: string}
     */
    public function wssEndpoint(): array
    {
        $wssUrl = $this->clickToCall->resolveSipWssUrl();
        $parsed = parse_url($wssUrl);

        return [
            'url' => $wssUrl,
            'host' => (string) ($parsed['host'] ?? $this->clickToCall->publicWssHost()),
            'port' => (int) ($parsed['port'] ?? 7443),
            'path' => (string) ($parsed['path'] ?? '/'),
        ];
    }
}
