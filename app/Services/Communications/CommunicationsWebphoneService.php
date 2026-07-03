<?php

namespace App\Services\Communications;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Communications\MorpheusHubService;
use App\Services\Integrations\ZoomApiService;

class CommunicationsWebphoneService
{
    public function __construct(
        protected ZoomApiService $morpheus,
        protected CommunicationsAgentService $agents,
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

        $domain = (string) (config('integrations.morpheus.sip_host') ?: config('integrations.morpheus.host'));
        $wssUrl = (string) (config('integrations.morpheus.sip_wss_url') ?: "wss://{$domain}:7443");

        return [
            'enabled' => true,
            'extension' => $extensionNum,
            'domain' => $domain,
            'wss_url' => $wssUrl,
            'auth_user' => $extensionNum,
            'password' => $password,
            'display_name' => $user->name,
            'auto_answer_click_to_call' => (bool) config('integrations.morpheus.webphone_auto_answer', true),
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

        $password = (string) config('integrations.morpheus.extension_password', '');
        if ($password === '') {
            return ['ok' => false, 'error' => 'MORPHEUS_EXTENSION_PASSWORD is not configured on the server.'];
        }

        $normalized = preg_replace('/\D/', '', $extensionNum) ?: $extensionNum;
        $ext = collect(app(MorpheusHubService::class)->extensions())->first(
            fn (array $row) => (string) ($row['extension_num'] ?? '') === (string) $normalized
        );

        if (! $ext || empty($ext['id'])) {
            return ['ok' => false, 'error' => "Extension {$extensionNum} not found in Morpheus."];
        }

        $extResult = $this->morpheus->updateExtension((string) $ext['id'], [
            'password' => $password,
            'is_dialer_agent' => true,
            'status' => 'active',
        ]);

        if (isset($extResult['error']) && ! isset($extResult['id'])) {
            return ['ok' => false, 'error' => (string) $extResult['error']];
        }

        $userId = $ext['user_id'] ?? null;
        if ($userId) {
            $this->morpheus->updateUser((string) $userId, [
                'password' => $password,
                'status' => 'active',
            ]);
        }

        app(MorpheusHubService::class)->bustCache();

        return ['ok' => true, 'message' => 'Extension SIP credentials synced.'];
    }

    protected function extensionPassword(string $extensionNum): string
    {
        $configured = (string) config('integrations.morpheus.extension_password', '');
        if ($configured !== '') {
            return $configured;
        }

        return (string) env('MORPHEUS_EXTENSION_PASSWORD', '');
    }
}
