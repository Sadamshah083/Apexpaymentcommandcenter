<?php

namespace App\Services\Communications;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Integrations\ZoomApiService;
use App\Support\MorpheusSipIdentity;
use App\Support\UsPhoneNormalizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class CommunicationsAgentService
{
    /** @var array<int, Collection<int, User>> */
    protected static array $activeMembersRequestMemo = [];

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

    /**
     * Active workspace members (per-request memo only).
     * Do NOT put Eloquent models in Cache — unserialize returns __PHP_Incomplete_Class
     * and Call Monitoring fails with "Could not load workspace agents."
     *
     * @return Collection<int, User>
     */
    public function loadActiveWorkspaceMembers(Workspace $workspace): Collection
    {
        $workspaceId = (int) $workspace->id;
        if (isset(self::$activeMembersRequestMemo[$workspaceId])) {
            return self::$activeMembersRequestMemo[$workspaceId];
        }

        $members = $workspace->users()
            ->wherePivot('status', 'active')
            ->orderBy('name')
            ->get();

        return self::$activeMembersRequestMemo[$workspaceId] = $members;
    }

    public function forgetActiveWorkspaceMembersCache(int $workspaceId): void
    {
        unset(self::$activeMembersRequestMemo[$workspaceId]);
        // Clear any legacy broken cache entries from earlier optimization.
        Cache::forget('ws:'.$workspaceId.':active_members_v1');
    }

    /**
     * Workspace agents by extension from DB only (no Morpheus HTTP).
     * Used by Call Monitoring light polls so role badges stay stable.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listLocalExtensionDirectory(Workspace $workspace): array
    {
        return $this->mapLocalExtensionDirectory($this->loadActiveWorkspaceMembers($workspace));
    }

    /**
     * @param  Collection<int, User>  $members
     * @return array<int, array<string, mixed>>
     */
    public function mapLocalExtensionDirectory(Collection $members): array
    {
        return $members
            ->map(function (User $user) {
                $pivot = $user->pivot;
                $ext = trim((string) ($pivot->morpheus_extension_num ?? ''));

                return [
                    'user_id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $pivot->role,
                    'role_label' => \App\Support\SalesOps::roleLabel($pivot->role),
                    'morpheus_extension_num' => $ext !== '' ? $ext : null,
                ];
            })
            ->filter(fn (array $agent) => filled($agent['morpheus_extension_num'] ?? null))
            ->values()
            ->all();
    }

    /**
     * Monitorable workspace agents for Call Monitoring roster (extension optional).
     * Ensures Admin / Team Lead always see NOT LOGGED IN rows with real usernames.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listMonitorableDirectory(Workspace $workspace): array
    {
        return $this->mapMonitorableDirectory($this->loadActiveWorkspaceMembers($workspace), $workspace);
    }

    /**
     * @param  Collection<int, User>  $members
     * @return array<int, array<string, mixed>>
     */
    public function mapMonitorableDirectory(Collection $members, Workspace $workspace): array
    {
        return $members
            ->map(function (User $user) use ($workspace) {
                $pivot = $user->pivot;
                $role = (string) ($pivot->role ?? '');
                $label = \App\Support\SalesOps::roleLabel($role);
                $ext = trim((string) ($pivot->morpheus_extension_num ?? ''));

                return [
                    'user_id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $role,
                    'role_label' => $label,
                    'morpheus_extension_num' => $ext !== '' ? $ext : null,
                    '_excluded' => AgentPresenceService::isExcludedFromMonitoring(
                        $role,
                        $label,
                        $user,
                        (int) $workspace->id
                    ),
                ];
            })
            ->filter(function (array $agent) {
                if ($agent['_excluded'] ?? false) {
                    return false;
                }

                return AgentPresenceService::isMonitorableRole((string) ($agent['role'] ?? ''));
            })
            ->map(function (array $agent) {
                unset($agent['_excluded']);

                return $agent;
            })
            ->values()
            ->all();
    }

    public function suggestExtensionNum(): string
    {
        $available = $this->availablePhoneLines();
        if ($available !== []) {
            return (string) ($available[0]['extension'] ?? '1001');
        }

        $nums = collect($this->hub->extensions())
            ->pluck('extension_num')
            ->map(fn ($n) => (int) preg_replace('/\D/', '', (string) $n))
            ->filter(fn ($n) => $n >= 1000 && $n < 10000);

        $next = $nums->isEmpty() ? 1001 : $nums->max() + 1;

        return (string) $next;
    }

    /**
     * DIDs available for assignment in Add Account / phone provisioning.
     * Extension is typed manually; this list powers the DID dropdown.
     *
     * @return list<array{did: string, label: string, suggested_extension: string|null}>
     */
    public function availablePhoneLines(?Workspace $workspace = null): array
    {
        $pool = config('morpheus_billing_dids.extensions', []);
        if (! is_array($pool) || $pool === []) {
            return [];
        }

        $defaultDid = preg_replace('/\D/', '', (string) config('integrations.communications.default_outbound_did', ''));

        // Extensions already linked to workspace members.
        $usedExtensions = collect();
        $pivotQuery = \Illuminate\Support\Facades\DB::table('workspace_user')
            ->whereNotNull('morpheus_extension_num')
            ->where('morpheus_extension_num', '!=', '');
        if ($workspace) {
            $pivotQuery->where('workspace_id', $workspace->id);
        }
        $usedExtensions = $pivotQuery
            ->pluck('morpheus_extension_num')
            ->map(fn ($n) => preg_replace('/\D/', '', (string) $n))
            ->filter()
            ->unique()
            ->values();

        // DIDs uniquely claimed on Morpheus (ignore the shared default CID noise).
        $usedDids = collect();
        try {
            $apiExtensions = collect($this->hub->extensions());
            foreach ($apiExtensions as $ext) {
                if (! is_array($ext)) {
                    continue;
                }
                $did = preg_replace('/\D/', '', (string) ($ext['caller_id_num'] ?? $ext['outbound_cid_num'] ?? ''));
                if ($did === '' || ($defaultDid !== '' && $did === $defaultDid)) {
                    continue;
                }
                $usedDids->push($did);
            }
        } catch (\Throwable) {
            // Morpheus unreachable — still show pool DIDs minus workspace-linked ones.
        }

        // Also treat billing DIDs for workspace-linked extensions as used.
        foreach ($usedExtensions as $ext) {
            $mapped = preg_replace('/\D/', '', (string) ($pool[$ext] ?? $pool[(string) $ext] ?? ''));
            if ($mapped !== '') {
                $usedDids->push($mapped);
            }
        }

        $usedDids = $usedDids->filter()->unique()->values();

        $lines = [];
        foreach ($pool as $extension => $did) {
            $ext = preg_replace('/\D/', '', (string) $extension);
            $didDigits = preg_replace('/\D/', '', (string) $did);
            if ($didDigits === '') {
                continue;
            }
            if ($usedDids->contains($didDigits)) {
                continue;
            }
            // Prefer unused extension numbers for the suggestion.
            $suggested = ($ext !== '' && ! $usedExtensions->contains($ext)) ? $ext : null;
            $formattedDid = strlen($didDigits) === 11 && str_starts_with($didDigits, '1')
                ? '+'.substr($didDigits, 0, 1).'-'.substr($didDigits, 1, 3).'-'.substr($didDigits, 4, 3).'-'.substr($didDigits, 7)
                : $didDigits;
            $lines[] = [
                'extension' => $suggested ?? $ext,
                'did' => $didDigits,
                'label' => $formattedDid.($suggested ? " (suggested ext {$suggested})" : ''),
                'suggested_extension' => $suggested,
            ];
        }

        return $lines;
    }

    /**
     * Next free extension number suggestion for the Add Account form.
     */
    public function suggestNextExtension(?Workspace $workspace = null): string
    {
        $pool = config('morpheus_billing_dids.extensions', []);
        $lines = $this->availablePhoneLines($workspace);
        foreach ($lines as $line) {
            if (! empty($line['suggested_extension'])) {
                return (string) $line['suggested_extension'];
            }
        }

        $used = \Illuminate\Support\Facades\DB::table('workspace_user')
            ->when($workspace, fn ($q) => $q->where('workspace_id', $workspace->id))
            ->whereNotNull('morpheus_extension_num')
            ->pluck('morpheus_extension_num')
            ->map(fn ($n) => (int) preg_replace('/\D/', '', (string) $n))
            ->filter()
            ->unique();

        $poolNums = collect(array_keys(is_array($pool) ? $pool : []))
            ->map(fn ($n) => (int) preg_replace('/\D/', '', (string) $n))
            ->filter()
            ->sort()
            ->values();

        foreach ($poolNums as $num) {
            if (! $used->contains($num)) {
                return (string) $num;
            }
        }

        $max = $poolNums->max() ?: 1000;

        return (string) ($max + 1);
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

        $extensionNum = preg_replace('/\D/', '', (string) ($data['extension_num'] ?? '')) ?: trim((string) ($data['extension_num'] ?? ''));
        if ($extensionNum === '') {
            return ['ok' => false, 'error' => 'Extension number is required.'];
        }

        $sipPassword = $this->ensureSipPassword((string) ($data['sip_password'] ?? ''));
        $callerIdName = (string) ($data['caller_id_name'] ?? $member->name);
        $callerIdNum = preg_replace('/\D/', '', (string) ($data['caller_id_num'] ?? ''));
        if ($callerIdNum === '') {
            $callerIdNum = preg_replace('/\D/', '', (string) (config("morpheus_billing_dids.extensions.{$extensionNum}") ?? ''));
        }

        $morpheusUserId = $pivot->morpheus_user_id;

        if (! $morpheusUserId && ($data['create_morpheus_user'] ?? true)) {
            $userResult = $this->morpheus->createUser([
                'username' => $this->morpheusUsername($member),
                'password' => $sipPassword,
                'email' => $member->email,
                'first_name' => Str::before($member->name, ' ') ?: $member->name,
                'last_name' => Str::contains($member->name, ' ') ? Str::after($member->name, ' ') : '',
                'role' => 'user',
                'status' => 'active',
                'user_level' => (int) ($data['user_level'] ?? 5),
            ]);

            if (isset($userResult['id'])) {
                $morpheusUserId = $userResult['id'];
            } elseif (isset($userResult['error'])) {
                $morpheusUserId = $this->findMorpheusUserId($member);
                if (! $morpheusUserId) {
                    return ['ok' => false, 'error' => (string) $userResult['error']];
                }
            }
        }

        $extensionPayload = array_filter([
            'extension_num' => $extensionNum,
            'password' => $sipPassword,
            'caller_id_name' => $callerIdName,
            'caller_id_num' => $callerIdNum !== '' ? $callerIdNum : null,
            'outbound_cid_name' => $callerIdName,
            'outbound_cid_num' => $callerIdNum !== '' ? $callerIdNum : null,
            'user_id' => $morpheusUserId,
            'status' => 'active',
            'voicemail_enabled' => true,
            'is_dialer_agent' => true,
            'override_campaign_cid' => true,
        ], fn ($v) => ! is_null($v));

        $extResult = $this->morpheus->createExtension($extensionPayload);
        $linkedExisting = false;

        if (isset($extResult['error']) && ! isset($extResult['id']) && ! isset($extResult['extension_num'])) {
            $existing = $this->findMorpheusExtensionByNumber($extensionNum);
            if (! $existing || empty($existing['id'])) {
                return ['ok' => false, 'error' => (string) $extResult['error']];
            }

            $patch = $this->morpheus->updateExtension((string) $existing['id'], array_filter([
                'password' => $sipPassword,
                'caller_id_name' => $callerIdName,
                'caller_id_num' => $callerIdNum !== '' ? $callerIdNum : null,
                'outbound_cid_name' => $callerIdName,
                'outbound_cid_num' => $callerIdNum !== '' ? $callerIdNum : null,
                'user_id' => $morpheusUserId,
                'status' => 'active',
                'is_dialer_agent' => true,
                'override_campaign_cid' => true,
            ], fn ($v) => ! is_null($v) && $v !== ''));

            if (isset($patch['error']) && ! isset($patch['id'])) {
                return ['ok' => false, 'error' => (string) $patch['error']];
            }

            $extResult = array_merge($existing, is_array($patch) ? $patch : []);
            $linkedExisting = true;
        }

        $workspace->users()->updateExistingPivot($member->id, [
            'morpheus_user_id' => $morpheusUserId,
            'morpheus_extension_id' => $extResult['id'] ?? null,
            'morpheus_extension_num' => $extResult['extension_num'] ?? $extensionNum,
        ]);
        $this->forgetActiveWorkspaceMembersCache((int) $workspace->id);

        if ($morpheusUserId) {
            $this->morpheus->updateUser($morpheusUserId, array_filter([
                'email' => $member->email,
                'first_name' => Str::before($member->name, ' ') ?: $member->name,
                'last_name' => Str::contains($member->name, ' ') ? Str::after($member->name, ' ') : '',
                'user_level' => (int) ($data['user_level'] ?? 5),
                'status' => 'active',
            ], fn ($v) => filled($v)));
        }

        $this->hub->bustCache();

        return [
            'ok' => true,
            'message' => $linkedExisting
                ? "Phone line {$extensionNum} linked and DID updated for {$member->name}."
                : "Phone line {$extensionNum} provisioned for {$member->name}.",
            'agent' => [
                'extension_num' => $extResult['extension_num'] ?? $extensionNum,
                'sip_password' => $sipPassword,
                'caller_id_num' => $callerIdNum !== '' ? $callerIdNum : null,
            ],
        ];
    }

    /**
     * Morpheus requires SIP passwords of at least 8 characters.
     * Login password can stay shorter (e.g. 123456); SIP gets a safe pad.
     */
    public function ensureSipPassword(string $password): string
    {
        $password = trim($password);
        if ($password === '') {
            $password = 'ApexOne1!';
        }
        if (strlen($password) >= 8) {
            return $password;
        }

        return $password.str_repeat('1', 8 - strlen($password));
    }

    protected function findMorpheusExtensionByNumber(string $extensionNum): ?array
    {
        $extensionNum = preg_replace('/\D/', '', $extensionNum);
        try {
            foreach ($this->hub->extensions() as $ext) {
                if (! is_array($ext)) {
                    continue;
                }
                $num = preg_replace('/\D/', '', (string) ($ext['extension_num'] ?? ''));
                if ($num === $extensionNum) {
                    return $ext;
                }
            }
        } catch (\Throwable) {
            // ignore
        }

        return null;
    }

    protected function findMorpheusUserId(User $member): ?string
    {
        try {
            $users = $this->morpheus->listUsers(['limit' => 500]);
            $rows = $users['users'] ?? (is_array($users) ? $users : []);
            $email = strtolower((string) $member->email);
            $username = strtolower($this->morpheusUsername($member));
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                if (strcasecmp((string) ($row['email'] ?? ''), $email) === 0) {
                    return isset($row['id']) ? (string) $row['id'] : null;
                }
                if (strcasecmp((string) ($row['username'] ?? ''), $username) === 0) {
                    return isset($row['id']) ? (string) $row['id'] : null;
                }
            }
        } catch (\Throwable) {
            // ignore
        }

        return null;
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
            // Keep campaign CID overrides from hijacking manual click-to-call.
            'override_campaign_cid' => true,
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

    /**
     * Fast extension list for the default dialer shell (no Morpheus API round-trip).
     *
     * @return array<int, array<string, mixed>>
     */
    public function dialerExtensionsFast(User $user, Workspace $workspace, string $routePrefix): array
    {
        $tier = app(CommunicationsAccessService::class)->tierFor($user, $routePrefix);
        $default = (string) (config('integrations.communications.default_caller_id') ?: '1020');

        if (in_array($tier, ['admin', 'supervisor'], true)) {
            $billing = config('morpheus_billing_dids.extensions', []);
            if (is_array($billing) && $billing !== []) {
                return collect($billing)
                    ->map(fn ($did, $extNum) => $this->formatDialerExtension([
                        'id' => null,
                        'extension_num' => (string) $extNum,
                        'caller_id_name' => $user->name,
                        'outbound_cid_num' => $did,
                        'status' => 'active',
                    ]))
                    ->sortBy('extension_num', SORT_NATURAL)
                    ->values()
                    ->all();
            }

            $extNum = $default;
        } else {
            // Agents/team leads must use their assigned line — never fall back to a shared default
            // (that caused 403 on webphone/config for unassigned users defaulting to 1020).
            $extNum = $this->extensionForUser($user, $workspace->id);
            if (! filled($extNum)) {
                return [];
            }
        }

        return [$this->formatDialerExtension([
            'id' => null,
            'extension_num' => $extNum,
            'caller_id_name' => $user->name,
            'caller_id_num' => null,
            'outbound_cid_num' => null,
            'status' => 'active',
        ])];
    }

    /**
     * Extensions shown in the dialer dropdown for the current user.
     *
     * @return array<int, array<string, mixed>>
     */
    public function dialerExtensionsFor(User $user, Workspace $workspace, string $routePrefix): array
    {
        $tier = app(CommunicationsAccessService::class)->tierFor($user, $routePrefix);
        $all = collect($this->hub->extensions());

        if (in_array($tier, ['admin', 'supervisor'], true)) {
            $formatted = $all
                ->map(fn (array $ext) => $this->formatDialerExtension($ext))
                ->filter(fn (array $ext) => filled($ext['extension_num']))
                ->values()
                ->all();

            if ($formatted !== []) {
                return $formatted;
            }

            $default = (string) (config('integrations.communications.default_caller_id') ?: '1020');

            return [$this->formatDialerExtension([
                'id' => null,
                'extension_num' => $default,
                'caller_id_name' => $user->name,
                'caller_id_num' => null,
                'outbound_cid_num' => null,
                'status' => 'active',
            ])];
        }

        $userExt = $this->extensionForUser($user, $workspace->id);
        if (! $userExt) {
            return [];
        }

        $match = $all->first(fn (array $ext) => (string) ($ext['extension_num'] ?? '') === $userExt);

        if ($match) {
            return [$this->formatDialerExtension($match)];
        }

        $pivot = $user->workspaces()->where('workspace_id', $workspace->id)->first()?->pivot;

        return [$this->formatDialerExtension([
            'id' => $pivot->morpheus_extension_id ?? null,
            'extension_num' => $userExt,
            'caller_id_name' => $user->name,
            'caller_id_num' => null,
            'outbound_cid_num' => null,
            'status' => 'active',
        ])];
    }

    public function userCanDialFrom(User $user, Workspace $workspace, string $extensionNum, string $routePrefix): bool
    {
        $tier = app(CommunicationsAccessService::class)->tierFor($user, $routePrefix);

        if (in_array($tier, ['admin', 'supervisor'], true)) {
            return true;
        }

        return (string) $this->extensionForUser($user, $workspace->id) === (string) (preg_replace('/\D/', '', $extensionNum) ?: $extensionNum);
    }

    /**
     * Caller ID and campaign options for Morpheus originate API.
     *
     * @return array<string, mixed>
     */
    public function extensionDialOptions(string $extensionNum): array
    {
        $normalized = preg_replace('/\D/', '', $extensionNum) ?: $extensionNum;

        $ext = collect($this->hub->extensions())->first(
            fn (array $row) => (string) ($row['extension_num'] ?? '') === (string) $normalized
        );

        // Always resolve CID for THIS extension only (billing map → Morpheus → no foreign DID).
        $callerIdNum = $this->resolveOutboundDidForExtension(
            (string) $normalized,
            is_array($ext) ? ($ext['outbound_cid_num'] ?? $ext['caller_id_num'] ?? null) : null,
        );

        if (! $ext) {
            return array_filter([
                'caller_id_number' => $this->morpheus->normalizeOriginateCallerId($callerIdNum),
                'campaign_id' => $this->morpheus->defaultOutboundCampaignId(),
            ], fn ($v) => filled($v));
        }

        $normalizedCallerId = $this->morpheus->normalizeOriginateCallerId($callerIdNum);
        $callerIdName = MorpheusSipIdentity::displayName(
            $ext['outbound_cid_name'] ?? $ext['caller_id_name'] ?? null,
            $normalizedCallerId,
        );

        return array_filter([
            'caller_id_number' => $normalizedCallerId,
            'caller_id_name' => $callerIdName !== '' ? $callerIdName : null,
            'campaign_id' => $this->morpheus->defaultOutboundCampaignId(),
        ], fn ($v) => filled($v));
    }

    public function extensionHasOutboundDid(string $extensionNum): bool
    {
        return filled($this->extensionDialOptions($extensionNum)['caller_id_number'] ?? null);
    }

    public function extensionOfflineDialMessage(string $extensionNum): string
    {
        $normalized = preg_replace('/\D/', '', $extensionNum) ?: $extensionNum;

        return "Extension {$normalized} is not connected — open the Phone panel and click Connect line before dialing. "
            .'Morpheus rings your browser line first, then dials the customer.';
    }

    public function extensionEndpointOnline(string $extensionNum): bool
    {
        if (app(\App\Services\Integrations\MorpheusCircuitBreaker::class)->isOpen()) {
            return (bool) config('integrations.morpheus.webrtc_enabled', true);
        }

        $normalized = preg_replace('/\D/', '', $extensionNum) ?: $extensionNum;

        $ext = collect($this->hub->extensions())->first(
            fn (array $row) => (string) ($row['extension_num'] ?? '') === (string) $normalized
        );

        if (! $ext) {
            return false;
        }

        return (bool) ($this->formatDialerExtension($ext)['endpoint_online'] ?? false);
    }

    /**
     * @param  array<string, mixed>  $ext
     * @return array<string, mixed>
     */
    public function resolveOutboundDid(?string $extensionNum, ?string $fromApi = null): ?string
    {
        return $this->resolveOutboundDidForExtension($extensionNum, $fromApi);
    }

    /**
     * Resolve the outbound DID for a specific agent line.
     * Prefer per-extension billing map, then Morpheus extension CID — never a different line's DID.
     */
    protected function resolveOutboundDidForExtension(?string $extensionNum, ?string $fromApi = null): ?string
    {
        $ext = trim((string) $extensionNum);
        if ($ext !== '') {
            $billingDid = config("morpheus_billing_dids.extensions.{$ext}");
            if (filled($billingDid)) {
                return $this->formatDidDisplay((string) $billingDid);
            }

            // Bound to THIS extension only — do not fall back to a shared workspace DID.
            if (filled($fromApi)) {
                return $this->formatDidDisplay((string) $fromApi);
            }

            return null;
        }

        if (filled($fromApi)) {
            return $this->formatDidDisplay((string) $fromApi);
        }

        $fallback = $this->defaultOutboundDid();

        return $fallback ? $this->formatDidDisplay($fallback) : null;
    }

    protected function formatDialerExtension(array $ext): array
    {
        $extNum = $ext['extension_num'] ?? null;
        $fromApi = $ext['outbound_cid_num'] ?? $ext['caller_id_num'] ?? null;
        $callerIdNum = $this->resolveOutboundDid(
            filled($extNum) ? (string) $extNum : null,
            filled($fromApi) ? (string) $fromApi : null,
        );
        $userId = $ext['user_id'] ?? null;
        $lastLogin = null;

        if ($userId) {
            $user = collect($this->hub->users())->first(
                fn (array $row) => (string) ($row['id'] ?? '') === (string) $userId
            );
            $lastLogin = $user['last_login_at'] ?? $user['last_login_time'] ?? null;
        }

        $webrtcEnabled = (bool) config('integrations.morpheus.webrtc_enabled', true);
        // With WebRTC, Morpheus last_login alone is unreliable — dialer uses
        // webphone_transport_connected (browser WSS REGISTER) as the real gate.
        $endpointOnline = filled($lastLogin) && ! $webrtcEnabled;
        $sipHost = (string) (config('integrations.morpheus.sip_host') ?: config('integrations.morpheus.host'));
        $portalUrl = app(\App\Services\Communications\ZoomClickToCallService::class)->portalUrl();

        return [
            'id' => $ext['id'] ?? null,
            'extension_num' => $ext['extension_num'] ?? null,
            'caller_id_name' => $ext['caller_id_name'] ?? $ext['outbound_cid_name'] ?? null,
            'caller_id_num' => $callerIdNum,
            'outbound_cid_num' => $callerIdNum,
            'status' => $ext['status'] ?? 'active',
            'is_dialer_agent' => (bool) ($ext['is_dialer_agent'] ?? false),
            'endpoint_online' => $endpointOnline,
            'endpoint_hint' => filled($lastLogin)
                ? null
                : ($webrtcEnabled
                    ? 'Click Connect in the Phone panel to register your browser line before dialing.'
                    : "Extension has not connected to Morpheus yet. Register SIP to {$sipHost} or open the Morpheus web phone before dialing."),
            'portal_url' => $portalUrl,
            'sip_host' => $sipHost,
        ];
    }

    protected function morpheusUsername(User $user): string
    {
        $base = Str::slug(Str::before($user->email, '@'), '_');
        if ($base === '') {
            $base = 'agent'.$user->id;
        }

        return substr($base, 0, 48);
    }

    protected function defaultOutboundDid(): ?string
    {
        $raw = (string) (config('integrations.communications.default_outbound_did') ?? '');
        $raw = trim($raw);

        return $raw !== '' ? $raw : null;
    }

    protected function formatDidDisplay(string $raw): string
    {
        return UsPhoneNormalizer::e164($raw) ?? trim($raw);
    }
}
