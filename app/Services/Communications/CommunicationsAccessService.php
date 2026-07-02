<?php

namespace App\Services\Communications;

use App\Models\User;
use Illuminate\Http\Request;

class CommunicationsAccessService
{
    /** Channels available to portal agents (setters, closers, team leads). */
    public const AGENT_CHANNELS = [
        'inbox',
        'calls',
        'recordings',
        'voicemail',
        'sms',
        'chat',
    ];

    /** Admin-only channels (configuration & telephony management). */
    public const ADMIN_ONLY_CHANNELS = [
        'queues',
        'conferences',
        'leads',
        'campaigns',
        'lists',
        'extensions',
        'team',
        'agents',
    ];

    /** Panels that configure Morpheus / workspace telephony. */
    public const ADMIN_ONLY_PANELS = [
        'settings',
        'team',
        'queues',
        'conferences',
        'leads',
        'campaigns',
        'lists',
        'extensions',
        'agents',
    ];

    public function isAgentTier(?User $user, string $routePrefix): bool
    {
        if ($routePrefix !== 'portal.') {
            return false;
        }

        return $user !== null && $user->canAccessPortal();
    }

    public function canConfigure(?User $user, string $routePrefix): bool
    {
        return ! $this->isAgentTier($user, $routePrefix);
    }

    /**
     * @return array<string, array{label: string, icon: string}>
     */
    public function channelsFor(?User $user, string $routePrefix): array
    {
        if ($this->canConfigure($user, $routePrefix)) {
            return CommunicationsInboxService::CHANNELS;
        }

        return collect(CommunicationsInboxService::CHANNELS)
            ->only(self::AGENT_CHANNELS)
            ->all();
    }

    public function canAccessChannel(?User $user, string $routePrefix, string $channel): bool
    {
        return array_key_exists($channel, $this->channelsFor($user, $routePrefix));
    }

    public function canAccessPanel(?User $user, string $routePrefix, string $panel): bool
    {
        if (in_array($panel, self::ADMIN_ONLY_PANELS, true)) {
            return $this->canConfigure($user, $routePrefix);
        }

        return true;
    }

    /**
     * Clamp channel/panel to what the current user may view.
     *
     * @return array{channel: string, panel: string, tier: string, can_configure: bool}
     */
    public function clampScope(
        Request $request,
        string $routePrefix,
        ?User $user,
        string $channel,
        string $panel,
    ): array {
        if (! $this->canAccessChannel($user, $routePrefix, $channel)) {
            $channel = 'inbox';
            $panel = 'empty';
        }

        if (! $this->canAccessPanel($user, $routePrefix, $panel)) {
            $panel = 'dialer';
        }

        if ($request->get('panel') === 'settings' && ! $this->canConfigure($user, $routePrefix)) {
            $panel = 'dialer';
        }

        if (
            $this->isAgentTier($user, $routePrefix)
            && $panel === 'empty'
            && ! $request->has('panel')
            && ! $request->filled('contact')
            && ! $request->filled('call')
            && ! $request->filled('session')
            && ! $request->filled('voicemail')
            && ! $request->filled('recording')
        ) {
            $panel = 'dialer';
        }

        return [
            'channel' => $channel,
            'panel' => $panel,
            'tier' => $this->isAgentTier($user, $routePrefix) ? 'agent' : 'admin',
            'can_configure' => $this->canConfigure($user, $routePrefix),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function viewMeta(?User $user, string $routePrefix): array
    {
        return [
            'tier' => $this->isAgentTier($user, $routePrefix) ? 'agent' : 'admin',
            'canConfigure' => $this->canConfigure($user, $routePrefix),
            'canManageTelephony' => $this->canConfigure($user, $routePrefix),
            'canDial' => true,
        ];
    }
}
