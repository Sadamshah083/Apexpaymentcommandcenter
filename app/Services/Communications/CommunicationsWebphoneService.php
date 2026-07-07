<?php

namespace App\Services\Communications;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Communications\MorpheusHubService;
use App\Services\Communications\ZoomClickToCallService;
use App\Services\Integrations\ZoomApiService;
use App\Support\MorpheusSipIdentity;

class CommunicationsWebphoneService
{
    public function __construct(
        protected ZoomApiService $morpheus,
        protected CommunicationsAgentService $agents,
        protected ZoomClickToCallService $clickToCall,
    ) {}

    /**
     * SIP/WebRTC settings for the embedded browser phone.
     *
     * @return array<string, mixed>|null
     */
    public function configFor(User $user, Workspace $workspace, string $extensionNum, string $routePrefix): ?array
    {
        if (! $this->morpheus->isConfigured()) {
            return null;
        }

        if (! (bool) config('integrations.morpheus.webrtc_enabled', true)) {
            return null;
        }

        $extensionNum = preg_replace('/\D/', '', $extensionNum) ?: $extensionNum;

        if ($extensionNum === '' || ! $this->agents->userCanDialFrom($user, $workspace, $extensionNum, $routePrefix)) {
            return null;
        }

        $password = $this->extensionPassword($extensionNum);
        if ($password === '') {
            return null;
        }

        $sipUser = $this->sipAuthUser($extensionNum);
        $sipDomain = $this->clickToCall->webrtcSipDomain();
        $publicHost = $this->clickToCall->publicWssHost();
        $dialOptions = $this->agents->extensionDialOptions($extensionNum);
        $callerIdNumber = $dialOptions['caller_id_number'] ?? null;
        $directWss = "wss://{$publicHost}:7443/";
        $proxyWss = $this->proxyWssUrl($publicHost);
        $wssUrl = $this->resolveWssUrl($publicHost, $directWss);

        return [
            'enabled' => true,
            'extension' => $extensionNum,
            'sip_user' => $sipUser,
            'domain' => $sipDomain,
            'dial_domain' => $sipDomain,
            'wss_url' => $wssUrl,
            'wss_url_fallback' => $this->wssFallback($wssUrl, $directWss, $proxyWss),
            'auth_user' => $sipUser,
            'password' => $password,
            'display_name' => MorpheusSipIdentity::displayName($user->name, $callerIdNumber),
            'auto_answer_click_to_call' => (bool) config('integrations.morpheus.webphone_auto_answer', true),
            'outbound_prefix' => (string) config('integrations.morpheus.outbound_prefix', ''),
            'sip_params' => (string) config('integrations.morpheus.sip_params', 'user=phone'),
            'outbound_caller_id' => $dialOptions['caller_id_number'] ?? null,
            'campaign_id' => $dialOptions['campaign_id'] ?? $this->morpheus->defaultOutboundCampaignId(),
            'ring_timeout_sec' => (int) config('integrations.morpheus.ring_timeout', 45),
            'stun_servers' => array_values(array_filter(
                array_map('trim', explode(',', (string) config('integrations.morpheus.stun_servers', 'stun:stun.l.google.com:19302')))
            )),
        ];
    }

    /**
     * Ensure extension + linked Morpheus user SIP passwords match config before webphone registers.
     */
    public function prepareExtension(User $user, Workspace $workspace, string $extensionNum, string $routePrefix): array
    {
        if (! $this->configFor($user, $workspace, $extensionNum, $routePrefix)) {
            return ['ok' => false, 'error' => 'Webphone not available for this extension.'];
        }

        $password = $this->extensionPassword($extensionNum);
        if ($password === '') {
            return ['ok' => false, 'error' => 'MORPHEUS_EXTENSION_PASSWORD is not configured on the server.'];
        }

        $normalized = preg_replace('/\D/', '', $extensionNum) ?: $extensionNum;
        $ext = $this->resolveExtension($user, $workspace, $normalized);
        $did = $this->configuredOutboundDidDigits();
        $warning = null;

        if ($ext !== null && ! empty($ext['id'])) {
            $campaignId = $this->morpheus->defaultOutboundCampaignId();
            $cidName = $did ? MorpheusSipIdentity::displayName(null, $did) : '';
            $extResult = $this->morpheus->updateExtension((string) $ext['id'], array_filter([
                'password' => $password,
                'is_dialer_agent' => true,
                'status' => 'active',
                'override_campaign_cid' => true,
                'caller_id_num' => $did,
                'caller_id_name' => $cidName !== '' ? $cidName : $did,
                'outbound_cid_num' => $did,
                'outbound_cid_name' => $cidName !== '' ? $cidName : $did,
                'campaign_id' => $campaignId,
            ], fn ($v) => filled($v)));

            if (isset($extResult['error']) && ! isset($extResult['id'])) {
                $warning = (string) $extResult['error'];
            }
        }

        $authUser = $this->sipAuthUser($normalized);
        $morpheusUser = null;
        if ($ext !== null && ! empty($ext['user_id'])) {
            $morpheusUser = $this->morpheus->getUser((string) $ext['user_id']);
        }
        if ($morpheusUser && ! empty($morpheusUser['id'])) {
            $userPatch = $this->morpheus->updateUser((string) $morpheusUser['id'], [
                'password' => $password,
                'status' => 'active',
            ]);
            if (isset($userPatch['error']) && ! isset($userPatch['id'])) {
                $warning = $warning ?: (string) $userPatch['error'];
            }
        }

        app(MorpheusHubService::class)->bustCache();

        if ($warning) {
            return [
                'ok' => true,
                'message' => 'Browser phone ready with configured SIP credentials.',
                'warning' => $warning,
            ];
        }

        return ['ok' => true, 'message' => 'Extension SIP credentials synced.'];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function resolveExtension(User $user, Workspace $workspace, string $normalized): ?array
    {
        $fromHub = collect(app(MorpheusHubService::class)->extensions())->first(
            fn (array $row) => (string) ($row['extension_num'] ?? '') === (string) $normalized
        );

        if ($fromHub) {
            return $fromHub;
        }

        $pivot = $user->workspaces()->where('workspace_id', $workspace->id)->first()?->pivot;

        if (filled($pivot?->morpheus_extension_id)) {
            $remote = $this->morpheus->getExtension((string) $pivot->morpheus_extension_id);
            if ($remote) {
                return $remote;
            }
        }

        foreach ($this->morpheus->listExtensions(['limit' => 500])['extensions'] ?? [] as $row) {
            if ((string) ($row['extension_num'] ?? '') === (string) $normalized) {
                app(MorpheusHubService::class)->bustCache();

                return $row;
            }
        }

        return null;
    }

    protected function sipAuthUser(string $extensionNum): string
    {
        return $extensionNum;
    }

    protected function extensionPassword(string $extensionNum): string
    {
        $configured = (string) config('integrations.morpheus.extension_password', '');
        if ($configured !== '') {
            return $configured;
        }

        return (string) env('MORPHEUS_EXTENSION_PASSWORD', '');
    }

    protected function configuredOutboundDidDigits(): ?string
    {
        $raw = trim((string) (config('integrations.communications.default_outbound_did') ?? ''));
        if ($raw === '') {
            return null;
        }

        $digits = preg_replace('/\D/', '', $raw);

        return $digits !== '' ? $digits : null;
    }

    protected function resolveWssUrl(string $publicHost, string $directWss): string
    {
        $configured = trim((string) config('integrations.morpheus.sip_wss_url', ''));
        if ($configured !== '') {
            return rtrim($configured, '/').'/';
        }

        return $directWss;
    }

    protected function wssFallback(string $primary, string $directWss, ?string $proxyWss): ?string
    {
        foreach ([$directWss, $proxyWss] as $candidate) {
            if (is_string($candidate) && $candidate !== '' && $candidate !== $primary) {
                return $candidate;
            }
        }

        return null;
    }

    protected function proxyWssUrl(string $publicHost): ?string
    {
        $appUrl = rtrim((string) config('app.url'), '/');
        if ($appUrl === '' || ! str_starts_with($appUrl, 'https://')) {
            return null;
        }

        $host = parse_url($appUrl, PHP_URL_HOST);
        if (! is_string($host) || $host === '' || $host === $publicHost) {
            return null;
        }

        return 'wss://'.$host.'/morpheus-ws/ws';
    }
}
