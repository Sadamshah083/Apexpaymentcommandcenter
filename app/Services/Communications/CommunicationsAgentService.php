<?php

namespace App\Services\Communications;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Integrations\ZoomApiService;
use Illuminate\Support\Str;

class CommunicationsAgentService
{
    public function __construct(
        protected ZoomApiService $morpheus,
        protected MorpheusHubService $hub,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForWorkspace(Workspace $workspace): array
    {
        $extensions = collect($this->hub->extensions())->keyBy('id');
        $extensionsByNum = $extensions->keyBy('extension_num');
        $morpheusUsers = collect($this->hub->users())->keyBy('id');

        return $workspace->users()
            ->wherePivot('status', 'active')
            ->orderBy('name')
            ->get()
            ->map(function (User $user) use ($workspace, $extensions, $extensionsByNum, $morpheusUsers) {
                $pivot = $user->pivot;
                $ext = null;

                if (filled($pivot->morpheus_extension_id)) {
                    $ext = $extensions->get($pivot->morpheus_extension_id);
                }
                if (! $ext && filled($pivot->morpheus_extension_num)) {
                    $ext = $extensionsByNum->get($pivot->morpheus_extension_num);
                }

                $morpheusUser = filled($pivot->morpheus_user_id)
                    ? $morpheusUsers->get($pivot->morpheus_user_id)
                    : null;

                return [
                    'user_id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $pivot->role,
                    'role_label' => \App\Support\SalesOps::roleLabel($pivot->role),
                    'morpheus_user_id' => $pivot->morpheus_user_id,
                    'morpheus_extension_id' => $pivot->morpheus_extension_id ?? ($ext['id'] ?? null),
                    'morpheus_extension_num' => $pivot->morpheus_extension_num ?? ($ext['extension_num'] ?? null),
                    'caller_id_name' => $ext['caller_id_name'] ?? $user->name,
                    'caller_id_num' => $ext['caller_id_num'] ?? ($ext['outbound_cid_num'] ?? null),
                    'extension_status' => $ext['status'] ?? null,
                    'provisioned' => filled($pivot->morpheus_extension_num) || filled($ext),
                    'morpheus_username' => $morpheusUser
                        ? trim(($morpheusUser['first_name'] ?? '').' '.($morpheusUser['last_name'] ?? ''))
                        : null,
                ];
            })
            ->values()
            ->all();
    }

    public function suggestExtensionNum(): string
    {
        $nums = collect($this->hub->extensions())
            ->pluck('extension_num')
            ->map(fn ($n) => (int) preg_replace('/\D/', '', (string) $n))
            ->filter(fn ($n) => $n >= 1000 && $n < 10000);

        $next = $nums->isEmpty() ? 1001 : $nums->max() + 1;

        return (string) $next;
    }

    /**
     * @param  array{extension_num: string, sip_password: string, caller_id_name?: string, caller_id_num?: string, create_morpheus_user?: bool}  $data
     * @return array{ok: bool, message?: string, error?: string, agent?: array<string, mixed>}
     */
    public function provision(Workspace $workspace, User $member, array $data): array
    {
        if (! $workspace->users()->where('user_id', $member->id)->exists()) {
            return ['ok' => false, 'error' => 'User is not a member of this workspace.'];
        }

        $pivot = $workspace->users()->where('user_id', $member->id)->first()->pivot;

        if (filled($pivot->morpheus_extension_id)) {
            return ['ok' => false, 'error' => 'This user already has a phone line. Update or remove it first.'];
        }

        $morpheusUserId = $pivot->morpheus_user_id;

        if (! $morpheusUserId && ($data['create_morpheus_user'] ?? true)) {
            $userResult = $this->morpheus->createUser([
                'username' => $this->morpheusUsername($member),
                'password' => $data['sip_password'],
                'email' => $member->email,
                'first_name' => Str::before($member->name, ' ') ?: $member->name,
                'last_name' => Str::contains($member->name, ' ') ? Str::after($member->name, ' ') : '',
                'role' => 'user',
                'status' => 'active',
            ]);

            if (isset($userResult['error']) && ! isset($userResult['id'])) {
                return ['ok' => false, 'error' => (string) $userResult['error']];
            }

            $morpheusUserId = $userResult['id'] ?? null;
        }

        $extensionPayload = array_filter([
            'extension_num' => $data['extension_num'],
            'password' => $data['sip_password'],
            'caller_id_name' => $data['caller_id_name'] ?? $member->name,
            'caller_id_num' => $data['caller_id_num'] ?? null,
            'outbound_cid_name' => $data['caller_id_name'] ?? $member->name,
            'outbound_cid_num' => $data['caller_id_num'] ?? null,
            'user_id' => $morpheusUserId,
            'status' => 'active',
            'voicemail_enabled' => true,
            'is_dialer_agent' => true,
        ], fn ($v) => ! is_null($v));

        $extResult = $this->morpheus->createExtension($extensionPayload);

        if (isset($extResult['error']) && ! isset($extResult['id']) && ! isset($extResult['extension_num'])) {
            return ['ok' => false, 'error' => (string) $extResult['error']];
        }

        $workspace->users()->updateExistingPivot($member->id, [
            'morpheus_user_id' => $morpheusUserId,
            'morpheus_extension_id' => $extResult['id'] ?? null,
            'morpheus_extension_num' => $extResult['extension_num'] ?? $data['extension_num'],
        ]);

        $this->hub->bustCache();

        return [
            'ok' => true,
            'message' => "Phone line {$data['extension_num']} provisioned for {$member->name}.",
            'agent' => [
                'extension_num' => $extResult['extension_num'] ?? $data['extension_num'],
                'sip_password' => $data['sip_password'],
            ],
        ];
    }

    /**
     * @param  array{sip_password?: string, caller_id_name?: string, caller_id_num?: string, status?: string}  $data
     */
    public function update(Workspace $workspace, User $member, array $data): array
    {
        $pivot = $workspace->users()->where('user_id', $member->id)->first()?->pivot;

        if (! $pivot || ! filled($pivot->morpheus_extension_id)) {
            return ['ok' => false, 'error' => 'No phone line is provisioned for this user.'];
        }

        $patch = array_filter([
            'password' => $data['sip_password'] ?? null,
            'caller_id_name' => $data['caller_id_name'] ?? null,
            'caller_id_num' => $data['caller_id_num'] ?? null,
            'outbound_cid_name' => $data['caller_id_name'] ?? null,
            'outbound_cid_num' => $data['caller_id_num'] ?? null,
            'status' => $data['status'] ?? null,
        ], fn ($v) => filled($v));

        if ($patch !== []) {
            $result = $this->morpheus->updateExtension($pivot->morpheus_extension_id, $patch);
            if (isset($result['error'])) {
                return ['ok' => false, 'error' => (string) $result['error']];
            }
        }

        $this->hub->bustCache();

        return ['ok' => true, 'message' => "Phone settings updated for {$member->name}."];
    }

    public function deprovision(Workspace $workspace, User $member): array
    {
        $pivot = $workspace->users()->where('user_id', $member->id)->first()?->pivot;

        if (! $pivot || ! filled($pivot->morpheus_extension_id)) {
            return ['ok' => false, 'error' => 'No phone line is provisioned for this user.'];
        }

        $result = $this->morpheus->deleteExtension($pivot->morpheus_extension_id);
        if (($result['ok'] ?? true) === false && isset($result['error'])) {
            return ['ok' => false, 'error' => (string) $result['error']];
        }

        $workspace->users()->updateExistingPivot($member->id, [
            'morpheus_extension_id' => null,
            'morpheus_extension_num' => null,
        ]);

        $this->hub->bustCache();

        return ['ok' => true, 'message' => "Phone line removed for {$member->name}."];
    }

    public function extensionForUser(User $user, ?int $workspaceId = null): ?string
    {
        $workspaceId = $workspaceId ?: $user->current_workspace_id;
        if (! $workspaceId) {
            return null;
        }

        $pivot = $user->workspaces()->where('workspace_id', $workspaceId)->first()?->pivot;

        return filled($pivot?->morpheus_extension_num)
            ? (string) $pivot->morpheus_extension_num
            : null;
    }

    protected function morpheusUsername(User $user): string
    {
        $base = Str::slug(Str::before($user->email, '@'), '_');
        if ($base === '') {
            $base = 'agent'.$user->id;
        }

        return substr($base, 0, 48);
    }
}
