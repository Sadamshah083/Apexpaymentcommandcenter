<?php

namespace App\Services\Integrations;

use App\Support\MorpheusSipIdentity;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * MorpheusCX Call-Control API client.
 *
 * Base URL: https://{host}/api/v1/call-control
 * Auth    : X-API-Key and Bearer (same Morpheus API key)
 *
 * Sections implemented (all endpoints from the OpenAPI spec):
 *   - Calls        : list, get, originate, click-to-call
 *   - CDR          : list (call history)
 *   - Recordings   : list, download
 *   - Voicemails   : list, download
 *   - Call Actions : hangup, hold, unhold, park, unpark, unbridge,
 *                    transfer, bridge, transfer-to-queue, transfer-to-agent,
 *                    join-conference, disposition
 *   - Queues       : list, get, create, update, delete, waiting
 *   - Conferences  : list, get, create, update, delete,
 *                    members, member-action, kick-all
 *   - Leads        : list, get, create, update, delete
 *   - Campaigns    : list, get, create, update, delete
 *   - Lists        : list, get, create, update, delete
 *   - Users        : list, get, create, update, delete
 *   - Extensions   : list, get, create, update, delete
 */
class ZoomApiService
{
    /** @var array<int, array<string, mixed>>|null */
    protected ?array $memoizedActiveCallLogs = null;

    /** @var array<string, string>|null */
    protected ?array $memoizedSipUsernameDidMap = null;

    /** @var array<string, string>|null */
    protected ?array $memoizedExtensionDidMap = null;

    // -------------------------------------------------------------------------
    // Identity / health
    // -------------------------------------------------------------------------

    public function isMorpheus(): bool
    {
        return true;
    }

    public function isConfigured(): bool
    {
        return filled(config('integrations.morpheus.api_key'))
            && filled(config('integrations.morpheus.host'));
    }

    /** @return array{connected: bool, message: string, expires_at: null} */
    public function connectionStatus(): array
    {
        $breaker = app(MorpheusCircuitBreaker::class);
        if ($breaker->isOpen()) {
            return [
                'connected' => false,
                'message' => $breaker->unavailableMessage(),
                'expires_at' => null,
            ];
        }

        $cached = Cache::get('integrations.morpheus.connection_status');
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $response = $this->pollClient()->get($this->url('/users'), ['limit' => 1]);

            if ($response->successful()) {
                $status = ['connected' => true, 'message' => 'Connected to Morpheus CX API.', 'expires_at' => null];
                $breaker->recordSuccess();
            } else {
                $status = ['connected' => false, 'message' => 'Morpheus API error: '.($response->json('error') ?? $response->body()), 'expires_at' => null];
            }
        } catch (\Throwable $e) {
            app(MorpheusCircuitBreaker::class)->reportFailure($e);
            $status = ['connected' => false, 'message' => 'Connection failed: '.$e->getMessage(), 'expires_at' => null];
        }

        $ttl = ($status['connected'] ?? false) ? 300 : 90;
        Cache::put('integrations.morpheus.connection_status', $status, $ttl);

        return $status;
    }

    /** @return array{phone_available: bool, messages: array<int, string>} */
    public function connectionDiagnostics(): array
    {
        $breaker = app(MorpheusCircuitBreaker::class);
        if ($breaker->isOpen()) {
            return [
                'phone_available' => false,
                'messages' => [$breaker->unavailableMessage()],
            ];
        }

        return Cache::remember('integrations.morpheus.connection_diagnostics', 300, function () {
            $messages = [];

            foreach ([
                '/cdr' => 'cdr:read (call history)',
                '/recordings' => 'recordings:read',
                '/voicemails' => 'voicemails:read',
            ] as $path => $label) {
                try {
                    $response = $this->pollClient()->get($this->url($path), ['limit' => 1]);
                    if ($response->status() === 403) {
                        $messages[] = "Missing permission: {$label}";
                    }
                } catch (\Throwable $e) {
                    app(MorpheusCircuitBreaker::class)->reportFailure($e);
                    $messages[] = "{$label}: ".$e->getMessage();
                }
            }

            return ['phone_available' => $messages === [], 'messages' => $messages];
        });
    }

    // -------------------------------------------------------------------------
    // Compat helpers (used by controller / settings view)
    // -------------------------------------------------------------------------

    public function accountId(): ?string   { return config('integrations.morpheus.host'); }
    public function clientId(): ?string    { return config('integrations.morpheus.api_key'); }
    public function webhookSecret(): ?string { return null; }
    public function requiredScopes(): array
    {
        return [
            'calls:read',
            'calls:control',
            'calls:originate',
            'cdr:read',
            'recordings:read',
            'voicemails:read',
            'queues:read',
            'conferences:read',
            'leads:read',
            'campaigns:read',
            'lists:read',
            'users:read',
            'extensions:read',
        ];
    }
    public function clearAccessTokenCache(): void {}
    public function humanizeError(string $msg): string { return $msg; }

    public function maskedSecret(): string
    {
        $key = (string) config('integrations.morpheus.api_key');
        return strlen($key) <= 8 ? '••••••••' : '••••••••' . substr($key, -4);
    }

    // =========================================================================
    // CALLS  (requires calls:read)
    // =========================================================================

    /**
     * GET /calls — List active calls.
     * @return array{calls: array<int, array<string, mixed>>}
     */
    public function listCalls(): array
    {
        try {
            $response = $this->client()->get($this->url('/calls'));
            if ($response->successful()) {
                return ['calls' => $response->json('calls') ?? []];
            }
        } catch (\Throwable) {}
        return ['calls' => []];
    }

    /**
     * Whether the outbound destination leg has actually answered (not just the agent).
     */
    public function destinationAnsweredOnCall(string $uuid, ?string $destination = null): bool
    {
        $snapshot = $this->resolveCallSnapshot($uuid);

        if ($snapshot === null) {
            return false;
        }

        foreach (['destination_answered', 'other_leg_answered', 'outbound_answered', 'callee_answered'] as $flag) {
            if (filter_var($snapshot[$flag] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                return true;
            }
        }

        foreach (['destination_answer_time', 'bridge_answer_time', 'callee_answer_time', 'b_leg_answer_time'] as $field) {
            if (filled($snapshot[$field] ?? null)) {
                return true;
            }
        }

        $destDigits = $this->normalizePhoneDigits($destination);

        $bridgedTo = $snapshot['bridged_to'] ?? null;
        if (filled($bridgedTo)) {
            $bLeg = $this->resolveCallSnapshot((string) $bridgedTo);
            if (is_array($bLeg) && $this->callLegMatchesDestination($bLeg, $destDigits) && $this->callLegIsAnswered($bLeg)) {
                return true;
            }
        }

        foreach ($snapshot['legs'] ?? $snapshot['call_legs'] ?? [] as $leg) {
            if (! is_array($leg)) {
                continue;
            }

            if ($this->callLegMatchesDestination($leg, $destDigits) && $this->callLegIsAnswered($leg)) {
                return true;
            }
        }

        $billsec = (int) ($snapshot['billsec'] ?? $snapshot['duration_sec'] ?? 0);
        if ($billsec >= 5 && $destDigits !== '') {
            foreach (['destination_number', 'callee_number', 'phone_number', 'destination', 'to'] as $field) {
                $raw = (string) ($snapshot[$field] ?? '');
                if (MorpheusSipIdentity::isSipContactHash($raw)) {
                    continue;
                }

                $digits = $this->normalizePhoneDigits($raw);
                if ($digits !== '' && ($digits === $destDigits || str_ends_with($destDigits, $digits) || str_ends_with($digits, $destDigits))) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function normalizePhoneDigits(?string $phone): string
    {
        return preg_replace('/\D/', '', (string) $phone) ?? '';
    }

    /**
     * @param  array<string, mixed>  $leg
     */
    protected function callLegMatchesDestination(array $leg, string $destDigits): bool
    {
        if ($destDigits === '') {
            return false;
        }

        foreach (['destination_number', 'callee_number', 'phone_number', 'caller_number', 'destination', 'to'] as $field) {
            $digits = $this->normalizePhoneDigits($leg[$field] ?? null);
            if ($digits === '') {
                continue;
            }

            if ($digits === $destDigits || str_ends_with($destDigits, $digits) || str_ends_with($digits, $destDigits)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $leg
     */
    protected function callLegIsAnswered(array $leg): bool
    {
        if (filter_var($leg['answered'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return true;
        }

        foreach (['answer_time', 'answered_at', 'destination_answer_time'] as $field) {
            if (filled($leg[$field] ?? null)) {
                return true;
            }
        }

        $state = strtoupper((string) ($leg['state'] ?? $leg['status'] ?? ''));

        return $state === 'ACTIVE' && filled($leg['answer_time'] ?? $leg['answered_at'] ?? null);
    }

    /**
     * GET /calls/{uuid} — Get a single live call (with CDR + active-call fallbacks).
     *
     * @return array<string, mixed>|null
     */
    public function getCall(string $uuid): ?array
    {
        return $this->resolveCallSnapshot($uuid);
    }

    /**
     * Resolve call state from live calls API, active call list, or recent CDR.
     *
     * @return array<string, mixed>|null
     */
    public function resolveCallSnapshot(string $uuid): ?array
    {
        $uuid = trim($uuid);
        if ($uuid === '') {
            return null;
        }

        $direct = $this->quickGetCall($uuid);
        if ($direct !== null) {
            return $direct;
        }

        foreach ($this->listActiveCalls() as $row) {
            if ($this->callRowMatchesUuid($row, $uuid)) {
                return $row;
            }
        }

        $cdr = $this->findRecentCdrByUuid($uuid);
        if ($cdr !== null) {
            return $this->normalizeCdrSnapshot($cdr);
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function listActiveCalls(): array
    {
        try {
            $response = $this->pollClient()->get($this->url('/calls'));
            if ($response->successful()) {
                $calls = $response->json('calls') ?? [];

                return is_array($calls) ? $calls : [];
            }
        } catch (\Throwable) {
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function callRowMatchesUuid(array $row, string $uuid): bool
    {
        foreach (['uuid', 'id', 'call_uuid', 'origination_uuid'] as $field) {
            if ((string) ($row[$field] ?? '') === $uuid) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function normalizeCdrSnapshot(array $row): array
    {
        $billsec = (int) ($row['billsec'] ?? $row['duration_sec'] ?? 0);
        $hangup = strtoupper((string) ($row['hangup_cause'] ?? ''));
        $outcome = strtolower((string) ($row['call_outcome'] ?? $row['disposition_code'] ?? ''));
        $ended = $hangup !== ''
            || $billsec > 0
            || in_array($outcome, ['connected', 'very_short', 'short', 'no_answer', 'busy', 'failed'], true);

        return array_merge($row, [
            'uuid' => $row['call_uuid'] ?? $row['uuid'] ?? null,
            'status' => $ended ? 'completed' : 'ringing',
            'live' => ! $ended,
            'billsec' => $billsec,
            'hangup_cause' => $hangup !== '' ? $hangup : null,
            'source' => 'cdr',
        ]);
    }

    /**
     * Fast call snapshot for originate verification (short HTTP timeout).
     *
     * @return array<string, mixed>|null
     */
    protected function quickGetCall(string $uuid): ?array
    {
        try {
            $response = $this->pollClient()->get($this->url("/calls/{$uuid}"));
            if ($response->successful()) {
                return $response->json();
            }
        } catch (\Throwable) {
        }

        return null;
    }

    /**
     * Click-to-call: rings extension first, then connects destination.
     *
     * @return array{ok: bool, call_uuid?: string, error?: string, action?: string}
     */
    public function clickToCall(string $extension, string $destination, array $options = []): array
    {
        $this->ensureManualOutboundCampaign();

        $response = $this->postOriginate('/click-to-call', array_merge([
            'extension' => trim($extension),
            'destination' => $this->formatClickToCallApiDestination($destination),
            'timeout_sec' => $this->originateRingTimeout(),
        ], $this->originatePayloadExtras($options)));

        if (($response['ok'] ?? false) && filled($response['call_uuid'] ?? null)
            && ! $this->verifyOriginateStarted((string) $response['call_uuid'])) {
            return array_merge($response, [
                'ok' => false,
                'routing_error' => true,
                'error' => 'Morpheus accepted click-to-call but no call was created on the PBX.',
            ]);
        }

        return $response;
    }

    /**
     * Originate via click-to-call (extension) or /calls/originate (from/to).
     *
     * @return array{ok: bool, call_uuid?: string, error?: string, action?: string, attempted?: array<int, string>}
     */
    public function originateCall(string $fromExtension, string $destination, array $options = []): array
    {
        $fromExtension = trim($fromExtension);
        $dialDestination = $this->formatClickToCallApiDestination($destination);

        if ($fromExtension === '' || $dialDestination === '') {
            return ['ok' => false, 'error' => 'Extension and destination are required.'];
        }

        $this->clearExtensionForOutboundDial($fromExtension, kickSip: false);
        usleep(1_500_000);

        if ($this->extensionHasLiveCalls($fromExtension)) {
            $this->clearExtensionForOutboundDial($fromExtension, kickSip: true);
            sleep(3);

            if ($this->extensionHasLiveCalls($fromExtension)) {
                return [
                    'ok' => false,
                    'extension_busy' => true,
                    'error' => "Extension {$fromExtension} is still busy. Wait 10–15 seconds, click Connect line, then try again.",
                    'attempted' => ['pre-check-busy'],
                ];
            }
        }

        $this->ensureManualOutboundCampaign();
        $extra = $this->originatePayloadExtras($options);

        $timeout = $this->originateRingTimeout();
        $attempted = [];
        $method = strtolower((string) config('integrations.morpheus.originate_method', 'originate'));
        $customerFirst = (bool) ($options['customer_first'] ?? config('integrations.morpheus.originate_customer_first', false));
        $isPstn = strlen($dialDestination) > 6;
        $lineReset = false;

        $place = function () use ($customerFirst, $isPstn, $method, $fromExtension, $dialDestination, $timeout, $extra, &$attempted): array {
            if ($customerFirst && $isPstn && $method !== 'click-to-call') {
                $response = $this->postOriginate('/calls/originate', array_merge([
                    'from' => $dialDestination,
                    'to' => $this->customerFirstBridgeExtension($fromExtension),
                    'timeout_sec' => $timeout,
                ], $extra));
                $attempted[] = 'POST /calls/originate (customer-first)';

                return $response;
            }

            if ($method === 'click-to-call') {
                $response = $this->postOriginate('/click-to-call', array_merge([
                    'extension' => $fromExtension,
                    'destination' => $dialDestination,
                    'timeout_sec' => $timeout,
                ], $extra));
                $attempted[] = 'POST /click-to-call';

                return $response;
            }

            $response = $this->postOriginate('/calls/originate', array_merge([
                'from' => $fromExtension,
                'to' => $dialDestination,
                'timeout_sec' => $timeout,
            ], $extra));
            $attempted[] = 'POST /calls/originate';

            return $response;
        };

        $response = $place();

        if (($response['ok'] ?? false) && filled($response['call_uuid'] ?? null)) {
            $uuid = (string) $response['call_uuid'];
            $busy = false;

            for ($i = 0; $i < 3; $i++) {
                usleep(1_000_000);
                $snap = $this->quickGetCall($uuid) ?? [];
                $cause = strtoupper((string) ($snap['hangup_cause'] ?? ''));
                $live = (bool) ($snap['live'] ?? false);

                if ($live) {
                    break;
                }

                if (in_array($cause, ['USER_BUSY', 'CALL_REJECTED'], true)) {
                    $busy = true;
                    break;
                }
            }

            if ($busy) {
                $this->hangup($uuid);
                $this->clearExtensionForOutboundDial($fromExtension, kickSip: true);
                $lineReset = true;
                sleep(2);
                $response = $place();
                $attempted[] = 'retry-after-busy';

                if (($response['ok'] ?? false) && filled($response['call_uuid'] ?? null)) {
                    usleep(1_500_000);
                    $retrySnap = $this->quickGetCall((string) $response['call_uuid']) ?? [];
                    $retryCause = strtoupper((string) ($retrySnap['hangup_cause'] ?? ''));
                    if (in_array($retryCause, ['USER_BUSY', 'CALL_REJECTED'], true)) {
                        $this->hangup((string) $response['call_uuid']);

                        return [
                            'ok' => false,
                            'extension_busy' => true,
                            'line_reset' => $lineReset,
                            'error' => "Extension {$fromExtension} is busy on Morpheus. Click Connect line again, or ask your admin to clear stuck calls on this extension.",
                            'hangup_cause' => $retryCause,
                            'attempted' => $attempted,
                        ];
                    }
                }
            }
        }

        if (! ($response['ok'] ?? false)) {
            return array_merge($response, ['attempted' => $attempted, 'line_reset' => $lineReset]);
        }

        $callUuid = (string) ($response['call_uuid'] ?? '');
        if ($callUuid !== '' && ! $this->verifyOriginateStarted($callUuid)) {
            return [
                'ok' => false,
                'routing_error' => true,
                'error' => 'Morpheus accepted the dial but no call was created on the PBX. Ask Morpheus support to check FreeSWITCH/originate routing for campaign '
                    .($extra['campaign_id'] ?? $this->defaultOutboundCampaignId()).'.',
                'call_uuid' => $callUuid,
                'attempted' => $attempted,
                'line_reset' => $lineReset,
            ];
        }

        if ($callUuid !== '') {
            $routingIssue = $this->detectMisroutedDestination($callUuid, $dialDestination);
            if ($routingIssue !== null) {
                $this->hangup($callUuid);

                return [
                    'ok' => false,
                    'routing_error' => true,
                    'extension_busy' => str_contains(strtolower($routingIssue), 'busy'),
                    'error' => $routingIssue,
                    'call_uuid' => $callUuid,
                    'attempted' => $attempted,
                    'line_reset' => $lineReset,
                ];
            }
        }

        return array_merge($response, [
            'ok' => true,
            'outcome' => 'initiated',
            'customer_first' => $customerFirst && $isPstn,
            'line_reset' => $lineReset,
            'from' => preg_replace('/\D/', '', $fromExtension) ?: $fromExtension,
            'to' => ltrim($dialDestination, '+'),
            'internal_from' => true,
            'campaign_id' => $extra['campaign_id'] ?? $this->defaultOutboundCampaignId(),
            'attempted' => $attempted,
        ]);
    }

    /**
     * Morpheus click-to-call expects bare PSTN digits; trunk tech prefix is applied server-side.
     */
    protected function formatClickToCallApiDestination(string $destination): string
    {
        $normalized = app(\App\Services\Communications\ZoomClickToCallService::class)
            ->normalizePhone($destination);
        $digits = preg_replace('/\D/', '', $normalized) ?? '';

        if ($digits !== '' && strlen($digits) === 10) {
            $digits = '1'.$digits;
        }

        return $digits;
    }

    /**
     * Clear active + stale calls before placing a new outbound request.
     *
     * @return array<int, string>
     */
    public function releaseCallsForExtension(string $extension, bool $aggressive = true): array
    {
        $normalized = preg_replace('/\D/', '', $extension) ?: $extension;
        $released = $this->releaseStaleActiveCalls($aggressive ? 0 : 60, $normalized);

        foreach ($this->listActiveCalls() as $call) {
            if (! $this->activeCallTouchesExtension($call, $normalized)) {
                continue;
            }

            $uuid = (string) ($call['uuid'] ?? $call['call_uuid'] ?? '');
            if ($uuid === '' || in_array($uuid, $released, true)) {
                continue;
            }

            $hangup = $this->hangup($uuid);
            if ($hangup['ok'] ?? false) {
                $released[] = $uuid;
            }
        }

        return array_values(array_unique($released));
    }

    /**
     * Hang up stale calls and optionally rotate SIP password to clear USER_BUSY zombies.
     *
     * @return array{released: array<int, string>, kicked: bool}
     */
    public function clearExtensionForOutboundDial(string $extensionNum, bool $kickSip = false): array
    {
        $normalized = preg_replace('/\D/', '', $extensionNum) ?: $extensionNum;
        $released = $this->releaseCallsForExtension($normalized, aggressive: true);

        $kicked = $kickSip ? $this->kickExtensionSipRegistration($normalized) : false;

        return ['released' => $released, 'kicked' => $kicked];
    }

    /**
     * Rotate SIP password to drop zombie registrations blocking USER_BUSY on an extension.
     */
    public function kickExtensionSipRegistration(string $extensionNum): bool
    {
        $normalized = preg_replace('/\D/', '', $extensionNum) ?: $extensionNum;
        $cacheKey = 'integrations.morpheus.extension_kick.'.$normalized;

        if (Cache::has($cacheKey)) {
            return false;
        }

        $extensionId = null;
        foreach ($this->listExtensions(['limit' => 100])['extensions'] ?? [] as $row) {
            if ((string) ($row['extension_num'] ?? '') === (string) $normalized) {
                $extensionId = (string) ($row['id'] ?? '');
                break;
            }
        }

        if (! filled($extensionId)) {
            return false;
        }

        $password = (string) (config('integrations.morpheus.extension_password') ?: '');
        if ($password === '') {
            return false;
        }

        $result = $this->updateExtension($extensionId, [
            'status' => 'active',
            'password' => $password,
        ]);

        if (filled($result['id'] ?? null)) {
            Cache::put($cacheKey, true, 120);

            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $call
     */
    protected function activeCallTouchesExtension(array $call, string $extensionNum): bool
    {
        if ($extensionNum === '') {
            return true;
        }

        foreach ([
            'extension', 'extension_num', 'from', 'to', 'caller', 'callee',
            'caller_id_number', 'destination_number', 'destination',
        ] as $field) {
            $value = preg_replace('/\D/', '', (string) ($call[$field] ?? '')) ?? '';
            if ($value !== '' && ($value === $extensionNum || str_ends_with($value, $extensionNum))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Zombie calls on an extension block new outbound with SIP 486 USER_BUSY.
     *
     * @return array<int, string>
     */
    public function releaseStaleActiveCalls(int $olderThanSeconds = 15, ?string $extensionNum = null): array
    {
        $released = [];

        foreach ($this->listActiveCalls() as $call) {
            if ($extensionNum !== null && ! $this->activeCallTouchesExtension($call, $extensionNum)) {
                continue;
            }

            $uuid = (string) ($call['uuid'] ?? $call['call_uuid'] ?? '');
            if ($uuid === '') {
                continue;
            }

            $startedAt = strtotime((string) ($call['started_at'] ?? '')) ?: 0;
            $age = $startedAt > 0 ? (time() - $startedAt) : PHP_INT_MAX;

            if ($age < $olderThanSeconds) {
                continue;
            }

            $this->hangup($uuid);
            $released[] = $uuid;
        }

        return $released;
    }

    protected function originateRingTimeout(): int
    {
        $configured = (int) config('integrations.morpheus.ring_timeout', 90);

        return min(120, max(60, $configured));
    }

    /**
     * Morpheus sometimes returns HTTP 200 + call_uuid while FreeSWITCH never creates the call.
     */
    protected function verifyOriginateStarted(string $uuid): bool
    {
        for ($i = 0; $i < 5; $i++) {
            usleep(600_000);

            if ($this->quickGetCall($uuid) !== null) {
                return true;
            }

            foreach ($this->listActiveCalls() as $row) {
                if ($this->callRowMatchesUuid($row, $uuid)) {
                    return true;
                }
            }

            if ($this->findRecentCdrByUuid($uuid) !== null) {
                return true;
            }
        }

        return false;
    }

    protected function customerFirstBridgeExtension(string $preferredExtension): string
    {
        return preg_replace('/\D/', '', $preferredExtension) ?: $preferredExtension;
    }

    /**
     * Ratio/predictive campaigns ignore the dialer destination and pull hopper leads instead.
     */
    protected function ensureManualOutboundCampaign(): void
    {
        $campaignId = $this->defaultOutboundCampaignId();
        if (! filled($campaignId)) {
            return;
        }

        $campaign = $this->getCampaign((string) $campaignId);
        if (! is_array($campaign)) {
            return;
        }

        $ringTimeout = (int) ($campaign['ring_timeout'] ?? 30);
        $dropTimeout = (int) ($campaign['drop_timeout'] ?? 5);
        $needsPatch = ($campaign['dial_mode'] ?? '') !== 'manual'
            || $ringTimeout < 90
            || $dropTimeout < 45;

        if (! $needsPatch) {
            return;
        }

        $this->updateCampaign((string) $campaignId, [
            'dial_mode' => 'manual',
            'status' => 'active',
            'require_disposition' => false,
            'ring_timeout' => 90,
            'drop_timeout' => 45,
        ]);
    }

    /**
     * Normalize Morpheus click-to-call response for hub JSON clients.
     *
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $dialOptions
     * @return array<string, mixed>
     */
    public function formatOriginateResponse(
        array $result,
        string $fromExtension,
        string $destination,
        array $dialOptions = [],
    ): array {
        $from = preg_replace('/\D/', '', $fromExtension) ?: $fromExtension;
        $to = $this->normalizeOriginateDestination($destination);

        return array_filter([
            'ok' => (bool) ($result['ok'] ?? false),
            'action' => $result['action'] ?? 'originate',
            'call_uuid' => $result['call_uuid'] ?? null,
            'campaign_id' => $result['campaign_id'] ?? ($dialOptions['campaign_id'] ?? $this->defaultOutboundCampaignId()),
            'from' => $from !== '' ? $from : null,
            'caller_id_number' => $this->normalizeOriginateCallerId(
                $dialOptions['caller_id_number'] ?? config('integrations.communications.default_outbound_did')
            ),
            'internal_from' => true,
            'outcome' => $result['outcome'] ?? null,
            'to' => $to !== '' ? $to : null,
            'attempted' => $result['attempted'] ?? ['POST /click-to-call'],
            'error' => $result['error'] ?? null,
            'warning' => $result['warning'] ?? null,
            'extension_offline' => $result['extension_offline'] ?? null,
            'extension_busy' => $result['extension_busy'] ?? null,
            'routing_error' => $result['routing_error'] ?? null,
            'hangup_cause' => $result['hangup_cause'] ?? null,
            'sip_code' => $result['sip_code'] ?? null,
            'customer_first' => $result['customer_first'] ?? null,
            'line_reset' => $result['line_reset'] ?? null,
        ], fn ($value) => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    protected function originatePayloadExtras(array $options = []): array
    {
        $callerIdNumber = $this->normalizeOriginateCallerId($options['caller_id_number'] ?? null);
        $callerIdName = MorpheusSipIdentity::displayName($options['caller_id_name'] ?? null, $callerIdNumber);

        return array_filter([
            'caller_id_number' => $callerIdNumber,
            'caller_id_name' => $callerIdName !== '' ? $callerIdName : null,
            'timeout_sec' => $options['timeout_sec'] ?? null,
            // Required by Morpheus click-to-call / originate APIs.
            'campaign_id' => $options['campaign_id'] ?? $this->defaultOutboundCampaignId(),
            'lead_id' => $options['lead_id'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    public function extensionHasLiveCalls(string $extension): bool
    {
        $normalized = preg_replace('/\D/', '', $extension) ?: $extension;

        foreach ($this->listActiveCalls() as $call) {
            if ($this->activeCallTouchesExtension($call, $normalized)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect Morpheus routing to a SIP contact hash instead of the PSTN destination.
     */
    protected function detectMisroutedDestination(string $callUuid, string $expectedDestination): ?string
    {
        $expectedDigits = preg_replace('/\D/', '', $expectedDestination) ?? '';
        if ($expectedDigits === '') {
            return null;
        }

        for ($i = 0; $i < 4; $i++) {
            usleep(750_000);
            $snap = $this->quickGetCall($callUuid) ?? [];
            $dest = (string) ($snap['destination_number'] ?? '');
            $cause = strtoupper((string) ($snap['hangup_cause'] ?? ''));

            if ($dest !== '' && MorpheusSipIdentity::isSipContactHash($dest)) {
                if (in_array($cause, ['USER_BUSY', 'CALL_REJECTED'], true)) {
                    return "Extension is busy — Morpheus tried to dial your browser contact ({$dest}) instead of the customer. Wait 10–15 seconds and try again.";
                }

                return "Morpheus misrouted the call to SIP contact {$dest} instead of {$expectedDigits}. Reconnect your line and try again.";
            }

            if ($dest !== '' && str_ends_with(preg_replace('/\D/', '', $dest) ?? '', substr($expectedDigits, -10))) {
                return null;
            }

            if (in_array($cause, ['USER_BUSY', 'CALL_REJECTED'], true) && ($snap['billsec'] ?? 0) === 0) {
                return 'Extension is busy on Morpheus. Wait 10–15 seconds, click Connect line, then dial again.';
            }
        }

        return null;
    }

    /**
     * Morpheus expects PSTN numbers as digits (no leading +).
     *
     * @see https://developers.morpheus.cx/reference/post_click-to-call
     */
    public function normalizeOriginateCallerId(?string $number): ?string
    {
        if (! filled($number)) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $number) ?? '';

        return $digits !== '' ? $digits : null;
    }

    /**
     * Morpheus click-to-call expects PSTN destinations as digits without carrier prefixes.
     */
    public function normalizeOriginateDestination(string $destination): string
    {
        $destination = trim($destination);

        if (str_contains($destination, '#')) {
            $destination = trim(substr($destination, strrpos($destination, '#') + 1));
        }

        $digits = preg_replace('/\D/', '', $destination) ?? '';

        return $digits !== '' ? $digits : ltrim($destination, '+');
    }

    /**
     * Campaign UUID used for hub originate / click-to-call requests.
     *
     * @see https://developers.morpheus.cx/reference/post_click-to-call
     */
    public function defaultOutboundCampaignId(): ?string
    {
        return $this->defaultCampaignId();
    }

    /**
     * Resolved outbound profile for admin settings + diagnostics.
     *
     * @return array<string, mixed>
     */
    public function outboundCallingProfile(): array
    {
        $campaignId = $this->defaultOutboundCampaignId();
        $campaignName = null;

        if ($campaignId) {
            $remote = $this->getCampaign((string) $campaignId);
            $campaignName = $remote['name'] ?? null;

            if ($campaignName === null) {
                foreach ($this->listCampaigns(['limit' => 100])['campaigns'] ?? [] as $campaign) {
                    if ((string) ($campaign['id'] ?? '') === (string) $campaignId) {
                        $campaignName = $campaign['name'] ?? null;
                        break;
                    }
                }
            }
        }

        $configuredDid = config('integrations.communications.default_outbound_did');

        return [
            'campaign_id' => $campaignId,
            'campaign_name' => $campaignName,
            'default_outbound_did' => filled($configuredDid)
                ? $this->normalizeOriginateCallerId((string) $configuredDid)
                : null,
            'default_extension' => config('integrations.communications.default_caller_id'),
            'api_docs_url' => 'https://developers.morpheus.cx/reference/post_click-to-call',
        ];
    }

    protected function defaultCampaignId(): ?string
    {
        $configured = config('integrations.morpheus.default_campaign_id');
        if (filled($configured)) {
            return (string) $configured;
        }

        try {
            $campaigns = $this->listCampaigns(['limit' => 50, 'status' => 'active'])['campaigns'] ?? [];

            foreach ($campaigns as $campaign) {
                if (($campaign['dial_mode'] ?? '') === 'manual') {
                    return (string) ($campaign['id'] ?? '');
                }
            }

            $first = $campaigns[0]['id'] ?? null;

            return $first !== null ? (string) $first : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{ok: bool, call_uuid?: string, error?: string, action?: string}
     */
    protected function postOriginate(string $path, array $body): array
    {
        try {
            \Log::info('Morpheus originate request', [
                'path' => $path,
                'extension' => $body['extension'] ?? $body['from'] ?? null,
                'destination' => $body['destination'] ?? $body['to'] ?? null,
                'campaign_id' => $body['campaign_id'] ?? null,
                'caller_id_number' => $body['caller_id_number'] ?? null,
            ]);

            $response = $this->client()->post($this->url($path), $body);

            if ($response->successful()) {
                $json = $response->json() ?? [];

                app(MorpheusCircuitBreaker::class)->recordSuccess();

                \Log::info('Morpheus originate response', [
                    'path' => $path,
                    'call_uuid' => $json['call_uuid'] ?? null,
                    'ok' => $json['ok'] ?? true,
                ]);

                return array_merge([
                    'ok' => (bool) ($json['ok'] ?? true),
                    'action' => 'originate',
                ], $json);
            }

            if ($response->status() === 403) {
                return ['ok' => false, 'error' => 'API key lacks calls:originate permission.'];
            }

            return [
                'ok' => false,
                'error' => (string) ($response->json('error') ?? 'Originate failed (HTTP '.$response->status().').'),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Poll Morpheus for the real result of a click-to-call request.
     *
     * @return array{ok: bool, outcome?: string, error?: string, warning?: string, extension_offline?: bool, extension_busy?: bool, routing_error?: bool, hangup_cause?: string, sip_code?: string}
     */
    protected function resolveOriginateOutcome(?string $callUuid, string $extension): array
    {
        if (blank($callUuid)) {
            return ['ok' => false, 'error' => 'Morpheus did not return a call ID.'];
        }

        $snapshot = $this->resolveCallSnapshot($callUuid);

        if ($snapshot === null) {
            usleep(450_000);
            $snapshot = $this->resolveCallSnapshot($callUuid);
        }

        if ($snapshot === null) {
            return [
                'ok' => true,
                'outcome' => 'ringing',
                'warning' => "Call request accepted by Morpheus ({$callUuid}). Connecting extension {$extension}…",
            ];
        }

        $hangup = strtoupper((string) ($snapshot['hangup_cause'] ?? ''));
        $sipCode = (string) ($snapshot['sip_code'] ?? '');
        $billsec = (int) ($snapshot['billsec'] ?? 0);
        $status = strtolower((string) ($snapshot['status'] ?? ''));
        $live = (bool) ($snapshot['live'] ?? false);

        if ($live || in_array($status, ['active', 'ringing'], true)) {
            return ['ok' => true, 'outcome' => 'ringing'];
        }

        if ($billsec >= 3) {
            return ['ok' => true, 'outcome' => 'connected'];
        }

        if (in_array($hangup, ['NORMAL_TEMPORARY_FAILURE', 'DESTINATION_OUT_OF_ORDER', 'NETWORK_OUT_OF_ORDER', 'SWITCH_CONGESTION'], true)) {
            return [
                'ok' => true,
                'outcome' => 'routing_failed',
                'warning' => 'Morpheus could not complete the outbound leg. Verify trunk routing and the destination number, then try again.',
                'hangup_cause' => $hangup,
            ];
        }

        if (in_array($hangup, ['USER_BUSY', 'CALL_REJECTED'], true) || in_array($sipCode, ['486', '603'], true)) {
            return [
                'ok' => false,
                'outcome' => 'extension_busy',
                'extension_busy' => true,
                'error' => "Extension {$extension} rejected the ring (SIP {$sipCode} {$hangup}). "
                    .'Close other calls, disable Do Not Disturb, then re-register your softphone to Morpheus.',
                'hangup_cause' => $hangup,
                'sip_code' => $sipCode,
            ];
        }

        if (in_array($hangup, ['NO_ROUTE_DESTINATION', 'UNALLOCATED_NUMBER', 'INVALID_NUMBER_FORMAT'], true)) {
            return [
                'ok' => false,
                'routing_error' => true,
                'error' => 'Morpheus could not route this outbound call. Verify trunk/DID routing in the Morpheus admin portal.',
                'hangup_cause' => $hangup,
            ];
        }

        if ($hangup === 'NO_ANSWER' || ($snapshot['call_outcome'] ?? '') === 'no_answer') {
            return [
                'ok' => true,
                'outcome' => 'no_answer',
                'warning' => "Extension {$extension} did not answer in time. Keep your softphone registered and ready.",
            ];
        }

        return [
            'ok' => true,
            'outcome' => 'initiated',
            'hangup_cause' => $hangup !== '' ? $hangup : null,
        ];
    }

    protected function extensionOfflineMessage(string $extension): string
    {
        $host = (string) (config('integrations.morpheus.sip_host') ?: config('integrations.morpheus.host'));
        $portal = app(\App\Services\Communications\ZoomClickToCallService::class)->portalUrl();

        return "Extension {$extension} is not online in Morpheus — no phone registered to ring. "
            ."Register a SIP softphone to {$host} (ext {$extension}, password from Phone Agents) "
            .'or sign in to the Morpheus web phone'
            .($portal !== '#' ? " ({$portal})" : '')
            .', then try again.';
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function findRecentCdrByUuid(string $callUuid): ?array
    {
        try {
            $response = $this->pollClient()->get($this->url('/cdr'), [
                'limit' => 50,
                'search' => $callUuid,
            ]);
            if (! $response->successful()) {
                $response = $this->pollClient()->get($this->url('/cdr'), ['limit' => 50]);
            }

            if (! $response->successful()) {
                return null;
            }

            $matches = [];
            foreach ($response->json('cdr') ?? [] as $row) {
                if ($this->callRowMatchesUuid($row, $callUuid)) {
                    $matches[] = $row;
                }
            }

            return $this->pickPrimaryCdrLeg($matches);
        } catch (\Throwable) {
        }

        return null;
    }

    /**
     * Morpheus emits multiple CDR rows per call (WebRTC/SIP bridge legs + PSTN leg).
     * Prefer the customer-facing PSTN leg for status/outcome resolution.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    protected function pickPrimaryCdrLeg(array $rows): ?array
    {
        if ($rows === []) {
            return null;
        }

        if (count($rows) === 1) {
            return $rows[0];
        }

        $scored = collect($rows)->map(function (array $row) {
            $destination = trim((string) ($row['destination_number'] ?? ''));
            $destDigits = preg_replace('/\D/', '', $destination) ?? '';
            $isPstnDestination = strlen($destDigits) >= 10 && ! preg_match('/[a-z]/i', $destination);
            $billsec = (int) ($row['billsec'] ?? 0);
            $score = ($isPstnDestination ? 1000 : 0) + $billsec;

            return ['row' => $row, 'score' => $score];
        })->sortByDesc('score')->values();

        return $scored->first()['row'] ?? $rows[0];
    }

    // =========================================================================
    // CALL ACTIONS  (requires calls:control)
    // =========================================================================

    /**
     * POST /calls/{uuid}/hangup — Hang up a call.
     * @return array{ok: bool, action: string}
     */
    public function hangup(string $uuid): array
    {
        $result = $this->callAction($uuid, 'hangup');

        if (! ($result['ok'] ?? false) && $this->isCallAlreadyEndedError((string) ($result['error'] ?? ''))) {
            return ['ok' => true, 'action' => 'hangup', 'already_ended' => true];
        }

        return $result;
    }

    /**
     * POST /calls/{uuid}/hold — Put a call on hold (MOH).
     */
    public function hold(string $uuid): array
    {
        return $this->callAction($uuid, 'hold');
    }

    /**
     * POST /calls/{uuid}/unhold — Remove a call from hold.
     */
    public function unhold(string $uuid): array
    {
        return $this->callAction($uuid, 'unhold');
    }

    /**
     * POST /calls/{uuid}/park — Park a call onto MOH.
     */
    public function park(string $uuid): array
    {
        return $this->callAction($uuid, 'park');
    }

    /**
     * POST /calls/{uuid}/unpark — Send a parked call to an extension/number.
     */
    public function unpark(string $uuid, string $destination): array
    {
        return $this->callAction($uuid, 'unpark', ['destination' => $destination]);
    }

    /**
     * POST /calls/{uuid}/unbridge — Split a call out of its bridge onto MOH.
     */
    public function unbridge(string $uuid): array
    {
        return $this->callAction($uuid, 'unbridge');
    }

    /**
     * POST /calls/{uuid}/transfer — Blind-transfer to extension or number.
     */
    public function transferCall(string $uuid, string $destination): array
    {
        return $this->callAction($uuid, 'transfer', ['destination' => $destination]);
    }

    /**
     * POST /calls/{uuid}/bridge — Bridge this call to another active call.
     */
    public function bridge(string $uuid, string $otherUuid): array
    {
        return $this->callAction($uuid, 'bridge', ['other_uuid' => $otherUuid]);
    }

    /**
     * POST /calls/{uuid}/transfer-to-queue — Put the call into a queue.
     */
    public function transferToQueue(string $uuid, string $queueId): array
    {
        return $this->callAction($uuid, 'transfer-to-queue', ['queue_id' => $queueId]);
    }

    /**
     * POST /calls/{uuid}/transfer-to-agent — Hand the call to a specific agent.
     */
    public function transferToAgent(string $uuid, string $agentUserId): array
    {
        return $this->callAction($uuid, 'transfer-to-agent', ['agent_user_id' => $agentUserId]);
    }

    /**
     * POST /calls/{uuid}/join-conference — Drop the call into a conference room.
     */
    public function joinConference(string $uuid, string $conference): array
    {
        return $this->callAction($uuid, 'join-conference', ['conference' => $conference]);
    }

    /**
     * POST /calls/{uuid}/disposition — Record a call outcome / disposition.
     *
     * @param  array{disposition: string, note?: string, update_lead?: bool} $data
     */
    public function dispositionCall(string $uuid, array $data): array
    {
        try {
            $response = $this->client()
                ->post($this->url("/calls/{$uuid}/disposition"), $data);
            return $response->json() ?? ['ok' => false];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // =========================================================================
    // QUEUES  (requires queues:read|create|update|delete)
    // =========================================================================

    /**
     * GET /queues — List queues (includes live waiting/longest_wait_sec state).
     * @return array{queues: array<int, array<string, mixed>>}
     */
    public function listQueues(): array
    {
        try {
            $response = $this->client()->get($this->url('/queues'));
            if ($response->successful()) {
                return ['queues' => $response->json('queues') ?? []];
            }
        } catch (\Throwable) {}
        return ['queues' => []];
    }

    /**
     * GET /queues/{id} — Get a single queue.
     */
    public function getQueue(string $id): ?array
    {
        try {
            $response = $this->client()->get($this->url("/queues/{$id}"));
            return $response->successful() ? $response->json() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * POST /queues — Create a queue.
     *
     * @param array{name: string, description?: string, strategy?: string, moh_sound?: string, max_wait_time?: int, wrap_up_time?: int, status?: string} $data
     */
    public function createQueue(array $data): array
    {
        try {
            $response = $this->client()->post($this->url('/queues'), $data);
            return $response->json() ?? ['error' => 'Unknown error'];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * PATCH /queues/{id} — Update a queue.
     */
    public function updateQueue(string $id, array $data): array
    {
        try {
            $response = $this->client()->patch($this->url("/queues/{$id}"), $data);
            return $response->json() ?? ['error' => 'Unknown error'];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * DELETE /queues/{id} — Delete a queue.
     */
    public function deleteQueue(string $id): array
    {
        try {
            $response = $this->client()->delete($this->url("/queues/{$id}"));
            return $response->json() ?? ['ok' => false];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * GET /queues/{id}/waiting — List callers currently waiting in a queue.
     */
    public function getQueueWaiting(string $id): array
    {
        try {
            $response = $this->client()->get($this->url("/queues/{$id}/waiting"));
            if ($response->successful()) {
                return ['waiting' => $response->json('waiting') ?? []];
            }
        } catch (\Throwable) {}
        return ['waiting' => []];
    }

    // =========================================================================
    // CONFERENCES  (requires conferences:read|create|update|delete|control)
    // =========================================================================

    /**
     * GET /conferences — List conference rooms.
     * @return array{conferences: array<int, array<string, mixed>>}
     */
    public function listConferences(): array
    {
        try {
            $response = $this->client()->get($this->url('/conferences'));
            if ($response->successful()) {
                return ['conferences' => $response->json('conferences') ?? []];
            }
        } catch (\Throwable) {}
        return ['conferences' => []];
    }

    /**
     * GET /conferences/{id} — Get a single conference room.
     */
    public function getConference(string $id): ?array
    {
        try {
            $response = $this->client()->get($this->url("/conferences/{$id}"));
            return $response->successful() ? $response->json() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * POST /conferences — Create a conference room.
     *
     * @param array{name: string, extension_num?: string, pin?: string, admin_pin?: string, max_members?: int, record?: bool, moh_sound?: string, announce?: bool, enabled?: bool} $data
     */
    public function createConference(array $data): array
    {
        try {
            $response = $this->client()->post($this->url('/conferences'), $data);
            return $response->json() ?? ['error' => 'Unknown error'];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * PATCH /conferences/{id} — Update a conference room.
     */
    public function updateConference(string $id, array $data): array
    {
        try {
            $response = $this->client()->patch($this->url("/conferences/{$id}"), $data);
            return $response->json() ?? ['error' => 'Unknown error'];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * DELETE /conferences/{id} — Delete a conference room.
     */
    public function deleteConference(string $id): array
    {
        try {
            $response = $this->client()->delete($this->url("/conferences/{$id}"));
            return $response->json() ?? ['ok' => false];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * GET /conferences/{id}/members — Get live roster of a conference room.
     */
    public function getConferenceMembers(string $id): array
    {
        try {
            $response = $this->client()->get($this->url("/conferences/{$id}/members"));
            if ($response->successful()) {
                return $response->json() ?? ['members' => []];
            }
        } catch (\Throwable) {}
        return ['members' => []];
    }

    /**
     * POST /conferences/{id}/members/{member}/{action} — Act on a conference member.
     *
     * @param string $action  mute|unmute|deaf|undeaf|kick
     */
    public function conferenceMemberAction(string $id, string $member, string $action): array
    {
        try {
            $response = $this->client()
                ->post($this->url("/conferences/{$id}/members/{$member}/{$action}"));
            return $response->json() ?? ['ok' => false];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * POST /conferences/{id}/kick-all — Remove all members from a conference room.
     */
    public function kickAllConferenceMembers(string $id): array
    {
        try {
            $response = $this->client()->post($this->url("/conferences/{$id}/kick-all"));
            return $response->json() ?? ['ok' => false];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // =========================================================================
    // LEADS  (requires leads:read|create|update|delete)
    // =========================================================================

    /**
     * GET /leads — List leads.
     *
     * @param array{limit?: int, offset?: int, list_id?: string, search?: string} $filters
     * @return array{leads: array<int, array<string, mixed>>}
     */
    public function listLeads(array $filters = []): array
    {
        try {
            $params = array_filter([
                'limit'   => $filters['limit']   ?? 100,
                'offset'  => $filters['offset']  ?? 0,
                'list_id' => $filters['list_id'] ?? null,
                'search'  => $filters['search']  ?? null,
            ], fn($v) => !is_null($v));

            $response = $this->client()->get($this->url('/leads'), $params);
            if ($response->successful()) {
                return ['leads' => $response->json('leads') ?? []];
            }
        } catch (\Throwable) {}
        return ['leads' => []];
    }

    /**
     * GET /leads/{id} — Get a single lead.
     */
    public function getLead(string $id): ?array
    {
        try {
            $response = $this->client()->get($this->url("/leads/{$id}"));
            return $response->successful() ? $response->json() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * POST /leads — Create a lead.
     *
     * @param array{phone_number: string, list_id: string, first_name?: string, last_name?: string, email?: string, ...} $data
     */
    public function createLead(array $data): array
    {
        try {
            $response = $this->client()->post($this->url('/leads'), $data);
            return $response->json() ?? ['error' => 'Unknown error'];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * PATCH /leads/{id} — Update a lead.
     */
    public function updateLead(string $id, array $data): array
    {
        try {
            $response = $this->client()->patch($this->url("/leads/{$id}"), $data);
            return $response->json() ?? ['error' => 'Unknown error'];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * DELETE /leads/{id} — Delete a lead.
     */
    public function deleteLead(string $id): array
    {
        try {
            $response = $this->client()->delete($this->url("/leads/{$id}"));
            return $response->json() ?? ['ok' => false];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // =========================================================================
    // CAMPAIGNS  (requires campaigns:read|create|update|delete)
    // =========================================================================

    /**
     * GET /campaigns — List campaigns.
     *
     * @param array{limit?: int, offset?: int, status?: string} $filters
     * @return array{campaigns: array<int, array<string, mixed>>}
     */
    public function listCampaigns(array $filters = []): array
    {
        try {
            $params = array_filter([
                'limit'  => $filters['limit']  ?? 100,
                'offset' => $filters['offset'] ?? 0,
                'status' => $filters['status'] ?? null,
            ], fn($v) => !is_null($v));

            $response = $this->client()->get($this->url('/campaigns'), $params);
            if ($response->successful()) {
                return ['campaigns' => $response->json('campaigns') ?? []];
            }
        } catch (\Throwable) {}
        return ['campaigns' => []];
    }

    /**
     * GET /campaigns/{id} — Get a single campaign.
     */
    public function getCampaign(string $id): ?array
    {
        try {
            $response = $this->client()->get($this->url("/campaigns/{$id}"));
            return $response->successful() ? $response->json() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * POST /campaigns — Create a campaign.
     *
     * @param array{name: string, dial_mode?: string, status?: string, dial_ratio?: float, ...} $data
     */
    public function createCampaign(array $data): array
    {
        try {
            $response = $this->client()->post($this->url('/campaigns'), $data);
            return $response->json() ?? ['error' => 'Unknown error'];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * PATCH /campaigns/{id} — Update a campaign.
     */
    public function updateCampaign(string $id, array $data): array
    {
        try {
            $response = $this->client()->patch($this->url("/campaigns/{$id}"), $data);
            return $response->json() ?? ['error' => 'Unknown error'];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * DELETE /campaigns/{id} — Delete a campaign.
     */
    public function deleteCampaign(string $id): array
    {
        try {
            $response = $this->client()->delete($this->url("/campaigns/{$id}"));
            return $response->json() ?? ['ok' => false];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // =========================================================================
    // LISTS  (requires lists:read|create|update|delete)
    // =========================================================================

    /**
     * GET /lists — List lead lists.
     *
     * @param array{limit?: int, offset?: int, campaign_id?: string} $filters
     * @return array{lists: array<int, array<string, mixed>>}
     */
    public function listLeadLists(array $filters = []): array
    {
        try {
            $params = array_filter([
                'limit'       => $filters['limit']       ?? 100,
                'offset'      => $filters['offset']      ?? 0,
                'campaign_id' => $filters['campaign_id'] ?? null,
            ], fn($v) => !is_null($v));

            $response = $this->client()->get($this->url('/lists'), $params);
            if ($response->successful()) {
                return ['lists' => $response->json('lists') ?? []];
            }
        } catch (\Throwable) {}
        return ['lists' => []];
    }

    /**
     * GET /lists/{id} — Get a single list.
     */
    public function getLeadList(string $id): ?array
    {
        try {
            $response = $this->client()->get($this->url("/lists/{$id}"));
            return $response->successful() ? $response->json() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * POST /lists — Create a lead list.
     *
     * @param array{name: string, description?: string, status?: string, campaign_id?: string, ...} $data
     */
    public function createLeadList(array $data): array
    {
        try {
            $response = $this->client()->post($this->url('/lists'), $data);
            return $response->json() ?? ['error' => 'Unknown error'];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * PATCH /lists/{id} — Update a list.
     */
    public function updateLeadList(string $id, array $data): array
    {
        try {
            $response = $this->client()->patch($this->url("/lists/{$id}"), $data);
            return $response->json() ?? ['error' => 'Unknown error'];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * DELETE /lists/{id} — Delete a list.
     */
    public function deleteLeadList(string $id): array
    {
        try {
            $response = $this->client()->delete($this->url("/lists/{$id}"));
            return $response->json() ?? ['ok' => false];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // =========================================================================
    // USERS  (requires users:read|create|update|delete)
    // =========================================================================

    /**
     * GET /users — List tenant users.
     *
     * @param array{limit?: int, offset?: int, search?: string} $filters
     * @return array{users: array<int, array<string, mixed>>}
     */
    public function listUsers(array $filters = []): array
    {
        try {
            $params = array_filter([
                'limit'  => $filters['limit']  ?? 100,
                'offset' => $filters['offset'] ?? 0,
                'search' => $filters['search'] ?? null,
            ], fn($v) => !is_null($v));

            $response = $this->client()->get($this->url('/users'), $params);
            if ($response->successful()) {
                $users = [];
                foreach ($response->json('users') ?? [] as $row) {
                    $users[] = [
                        'id'         => $row['id'],
                        'username'   => $row['username'] ?? null,
                        'first_name' => $row['first_name'] ?? ($row['username'] ?? ''),
                        'last_name'  => $row['last_name']  ?? '',
                        'email'      => $row['email']      ?? '',
                        'type'       => 'user',
                        'status'     => $row['status']     ?? 'active',
                        'last_login_at' => $row['last_login_at'] ?? null,
                        'last_login_time' => $row['last_login_at'] ?? null,
                    ];
                }
                return ['users' => $users, 'next_page_token' => null];
            }
        } catch (\Throwable) {}
        return ['users' => [], 'next_page_token' => null];
    }

    /**
     * GET /users/{id} — Get a single user.
     */
    public function getUser(string $id): ?array
    {
        try {
            $response = $this->client()->get($this->url("/users/{$id}"));
            return $response->successful() ? $response->json() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * POST /users — Create a user.
     *
     * @param array{username: string, password: string, first_name?: string, last_name?: string, email?: string, role?: string, status?: string} $data
     */
    public function createUser(array $data): array
    {
        try {
            $response = $this->client()->post($this->url('/users'), $data);
            return $response->json() ?? ['error' => 'Unknown error'];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * PATCH /users/{id} — Update a user.
     */
    public function updateUser(string $id, array $data): array
    {
        try {
            $response = $this->client()->patch($this->url("/users/{$id}"), $data);
            return $response->json() ?? ['error' => 'Unknown error'];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * DELETE /users/{id} — Delete a user.
     */
    public function deleteUser(string $id): array
    {
        try {
            $response = $this->client()->delete($this->url("/users/{$id}"));
            return $response->json() ?? ['ok' => false];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // =========================================================================
    // EXTENSIONS  (requires extensions:read|create|update|delete)
    // =========================================================================

    /**
     * GET /extensions — List SIP extensions (raw API rows).
     *
     * @param array{limit?: int, offset?: int, user_id?: string} $filters
     * @return array{extensions: array<int, array<string, mixed>>}
     */
    public function listExtensions(array $filters = []): array
    {
        try {
            $params = array_filter([
                'limit' => $filters['limit'] ?? 100,
                'offset' => $filters['offset'] ?? 0,
                'user_id' => $filters['user_id'] ?? null,
            ], fn ($v) => ! is_null($v));

            $response = $this->client()->get($this->url('/extensions'), $params);
            if ($response->successful()) {
                return ['extensions' => $response->json('extensions') ?? []];
            }
        } catch (\Throwable) {
        }

        return ['extensions' => []];
    }

    /**
     * GET /extensions — List SIP extensions.
     *
     * @param array{limit?: int, offset?: int, user_id?: string} $filters
     * @return array{users: array<int, array<string, mixed>>, next_page_token: null, warning: null}
     */
    public function listPhoneUsers(array $filters = []): array
    {
        try {
            $params = array_filter([
                'limit'   => $filters['limit']   ?? 100,
                'offset'  => $filters['offset']  ?? 0,
                'user_id' => $filters['user_id'] ?? null,
            ], fn($v) => !is_null($v));

            $response = $this->client()->get($this->url('/extensions'), $params);
            if ($response->successful()) {
                $users = [];
                foreach ($response->json('extensions') ?? [] as $row) {
                    $users[] = [
                        'id'               => $row['id'] ?? $row['extension_num'],
                        'name'             => $row['caller_id_name'] ?? ('Extension ' . $row['extension_num']),
                        'email'            => $row['vm_email'] ?? '',
                        'extension_number' => $row['extension_num'],
                        'phone_numbers'    => [$row['extension_num']],
                        'default_caller_id'=> $row['caller_id_num'] ?? $row['extension_num'],
                        'status'           => $row['status'] ?? 'active',
                    ];
                }
                return ['users' => $users, 'next_page_token' => null, 'warning' => null];
            }
            return ['users' => [], 'next_page_token' => null, 'warning' => 'Morpheus API error: ' . $response->body()];
        } catch (\Throwable $e) {
            return ['users' => [], 'next_page_token' => null, 'warning' => 'Connection failed: ' . $e->getMessage()];
        }
    }

    /**
     * GET /extensions/{id} — Get a single extension.
     */
    public function getExtension(string $id): ?array
    {
        try {
            $response = $this->client()->get($this->url("/extensions/{$id}"));
            return $response->successful() ? $response->json() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * POST /extensions — Create a SIP extension.
     *
     * @param array{extension_num: string, password: string, caller_id_name?: string, ...} $data
     */
    public function createExtension(array $data): array
    {
        try {
            $response = $this->client()->post($this->url('/extensions'), $data);
            return $response->json() ?? ['error' => 'Unknown error'];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * PATCH /extensions/{id} — Update an extension.
     */
    public function updateExtension(string $id, array $data): array
    {
        try {
            $response = $this->client()->patch($this->url("/extensions/{$id}"), $data);
            return $response->json() ?? ['error' => 'Unknown error'];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * DELETE /extensions/{id} — Delete an extension.
     */
    public function deleteExtension(string $id): array
    {
        try {
            $response = $this->client()->delete($this->url("/extensions/{$id}"));
            return $response->json() ?? ['ok' => false];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // =========================================================================
    // CALL LOG  (active calls + CDR history)
    // =========================================================================

    /**
     * @return array{logs: array<int, array<string, mixed>>, next_page_token: string|null, warning: string|null}
     */
    public function listCdr(array $filters = []): array
    {
        $limit = (int) ($filters['per_page'] ?? 50);
        $offset = is_numeric($filters['page_token'] ?? null) ? (int) $filters['page_token'] : 0;

        try {
            $response = $this->client()->get($this->url('/cdr'), array_filter([
                'from' => $this->cdrTimestamp($filters['from'] ?? null),
                'to' => $this->cdrTimestamp($filters['to'] ?? null, true),
                'direction' => $filters['direction'] ?? null,
                'search' => $filters['search'] ?? null,
                'limit' => $limit,
                'offset' => $offset,
            ], fn ($value) => $value !== null && $value !== ''));

            if ($response->status() === 403) {
                return [
                    'logs' => [],
                    'next_page_token' => null,
                    'warning' => 'API key lacks cdr:read permission.',
                ];
            }

            if (! $response->successful()) {
                return [
                    'logs' => [],
                    'next_page_token' => null,
                    'warning' => (string) ($response->json('error') ?? null),
                ];
            }

            $rows = $response->json('cdr') ?? [];
            $logs = collect(is_array($rows) ? $rows : [])
                ->reject(fn (array $row) => $this->isInternalCdrLeg($row))
                ->map(fn (array $row) => $this->normalizeCdrRow($row))
                ->values()
                ->all();

            $nextPageToken = count($logs) >= $limit ? (string) ($offset + $limit) : null;

            return ['logs' => $logs, 'next_page_token' => $nextPageToken, 'warning' => null];
        } catch (\Throwable $e) {
            return ['logs' => [], 'next_page_token' => null, 'warning' => $e->getMessage()];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function listActiveCallLogs(): array
    {
        if ($this->memoizedActiveCallLogs !== null) {
            return $this->memoizedActiveCallLogs;
        }

        try {
            $response = $this->client()->get($this->url('/calls'));
            if (! $response->successful()) {
                return $this->memoizedActiveCallLogs = [];
            }

            $logs = [];
            foreach ($response->json('calls') ?? [] as $row) {
                $logs[] = [
                    'id' => $row['uuid'],
                    'direction' => $row['direction'] ?? 'inbound',
                    'from' => $row['caller_name'] ?? $row['phone_number'] ?? '—',
                    'to' => $row['callee_name'] ?? '—',
                    'from_phone' => $row['phone_number'] ?? '',
                    'to_phone' => $row['phone_number'] ?? '',
                    'start_time' => $row['started_at'] ?? null,
                    'result' => ($row['status'] ?? '') === 'active' ? 'Active Call' : ($row['status'] ?? '—'),
                    'duration' => (int) ($row['duration'] ?? 0),
                    'recording' => '—',
                    'campaign_id' => $row['campaign_id'] ?? null,
                    'source' => 'live',
                    'raw' => $row,
                ];
            }

            return $this->memoizedActiveCallLogs = $logs;
        } catch (\Throwable) {
            return $this->memoizedActiveCallLogs = [];
        }
    }

    /**
     * Active calls plus CDR history for the hub call-log view.
     *
     * @return array{logs: array<int, array<string, mixed>>, call_logs: array<int, array<string, mixed>>, next_page_token: string|null, warning: string|null}
     */
    public function listCallLogs(array $filters = []): array
    {
        $warning = null;
        $active = $this->listActiveCallLogs();
        $cdr = $this->listCdr($filters);

        if (filled($cdr['warning'] ?? null)) {
            $warning = $cdr['warning'];
        }

        $activeIds = collect($active)->pluck('id')->filter()->all();
        $history = collect($cdr['logs'])
            ->reject(fn (array $row) => in_array($row['id'], $activeIds, true))
            ->values()
            ->all();

        $logs = collect($active)
            ->concat($history)
            ->sortByDesc(fn (array $row) => $row['start_time'] ?? '')
            ->values()
            ->all();

        return [
            'logs' => $logs,
            'call_logs' => $logs,
            'next_page_token' => $cdr['next_page_token'],
            'warning' => $warning,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function normalizeCdrRow(array $row): array
    {
        $callUuid = (string) ($row['call_uuid'] ?? $row['id'] ?? '');
        $hasRecording = (bool) ($row['has_recording'] ?? false);
        $agentExtension = trim((string) ($row['agent_extension'] ?? ''));
        $callerIdNumber = (string) ($row['caller_id_number'] ?? '');
        $callerIdName = (string) ($row['caller_id_name'] ?? '');
        $destination = $this->normalizeCdrDestination((string) ($row['destination_number'] ?? ''));
        $fromPhone = $this->resolveCdrFromPhone($agentExtension, $callerIdNumber, $destination);
        $fromLabel = $this->resolveCdrFromLabel($agentExtension, $callerIdName, $callerIdNumber, $fromPhone, $destination);

        return [
            'id' => $callUuid !== '' ? $callUuid : (string) ($row['id'] ?? ''),
            'direction' => $row['direction'] ?? 'inbound',
            'from' => $fromLabel,
            'to' => $destination !== '' ? $destination : '—',
            'from_phone' => $fromPhone,
            'to_phone' => $destination,
            'agent_extension' => $agentExtension !== '' ? $agentExtension : null,
            'start_time' => $row['start_time'] ?? null,
            'result' => $row['call_outcome'] ?? $row['disposition_code'] ?? $row['hangup_cause'] ?? '—',
            'duration' => (int) ($row['billsec'] ?? $row['duration_sec'] ?? 0),
            'recording' => $hasRecording ? 'Yes' : '—',
            'has_recording_media' => $hasRecording,
            'recording_id' => $hasRecording ? $callUuid : null,
            'call_reference_id' => $callUuid,
            'recording_source' => $hasRecording ? 'morpheus' : null,
            'campaign_id' => $row['campaign_id'] ?? null,
            'source' => 'cdr',
            'raw' => $row,
        ];
    }

    protected function normalizeCdrDestination(string $number): string
    {
        $number = trim($number);

        if ($number === '') {
            return '';
        }

        // Morpheus internal SIP legs use alphanumeric usernames (e.g. vv0aou9q) — not dialable numbers.
        if (preg_match('/[a-z]/i', $number)) {
            return '';
        }

        if (str_contains($number, '#')) {
            $number = trim(substr($number, strrpos($number, '#') + 1));
        }

        $digits = preg_replace('/\D/', '', $number) ?? '';

        if ($digits === '') {
            return $number;
        }

        if (strlen($digits) < 10) {
            return '';
        }

        if (strlen($digits) === 10) {
            return '+1'.$digits;
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return '+'.$digits;
        }

        return $digits;
    }

    /**
     * Morpheus emits extra CDR rows for internal WebRTC/SIP bridge legs. Hide them from the hub call log.
     */
    protected function isInternalCdrLeg(array $row): bool
    {
        $destination = trim((string) ($row['destination_number'] ?? ''));
        $agentExtension = trim((string) ($row['agent_extension'] ?? ''));
        $callerDigits = preg_replace('/\D/', '', (string) ($row['caller_id_number'] ?? '')) ?? '';
        $destDigits = preg_replace('/\D/', '', $destination) ?? '';

        if ($destination === '') {
            return true;
        }

        if (preg_match('/[a-z]/i', $destination)) {
            return true;
        }

        // Morpheus loopback legs echo the outbound DID as both caller and destination.
        if (strlen($callerDigits) >= 10 && strlen($destDigits) >= 10 && $callerDigits === $destDigits) {
            return true;
        }

        if (strlen($destDigits) >= 10) {
            return false;
        }

        return $agentExtension === '';
    }

    protected function resolveCdrFromPhone(string $agentExtension, string $callerIdNumber, string $destination = ''): string
    {
        $digits = preg_replace('/\D/', '', $callerIdNumber) ?? '';

        if (strlen($digits) >= 10) {
            return strlen($digits) === 10 ? '+1'.$digits : '+'.$digits;
        }

        $destDigits = preg_replace('/\D/', '', $destination) ?? '';

        if ($this->looksLikeSipUsername($callerIdNumber) && strlen($destDigits) >= 10) {
            $did = $this->resolveOutboundDidForCdr($agentExtension, $callerIdNumber);

            if ($did !== '') {
                return $did;
            }
        }

        if ($agentExtension !== '' && $this->looksLikeSipUsername($callerIdNumber)) {
            $did = $this->extensionOutboundDidE164($agentExtension);

            if ($did !== '') {
                return $did;
            }
        }

        if ($agentExtension !== '') {
            return $agentExtension;
        }

        return $callerIdNumber;
    }

    protected function resolveCdrFromLabel(
        string $agentExtension,
        string $callerIdName,
        string $callerIdNumber,
        string $fromPhone,
        string $destination = '',
    ): string {
        $destDigits = preg_replace('/\D/', '', $destination) ?? '';
        $isOutboundPstn = strlen($destDigits) >= 10;

        if (strlen(preg_replace('/\D/', '', $fromPhone) ?? '') >= 10) {
            if ($agentExtension !== '') {
                return "ext {$agentExtension} · {$fromPhone}";
            }

            if ($isOutboundPstn && $this->looksLikeSipUsername($callerIdNumber)) {
                return $fromPhone;
            }
        }

        if ($agentExtension !== '') {
            $name = trim($callerIdName);

            if ($name !== '' && ! in_array($name, ['Outbound Call', '—'], true)) {
                return "{$name} (ext {$agentExtension})";
            }

            return "ext {$agentExtension}";
        }

        if ($isOutboundPstn && $this->looksLikeSipUsername($callerIdNumber)) {
            $did = $this->resolveOutboundDidForCdr('', $callerIdNumber);

            return $did !== '' ? $did : 'Outbound';
        }

        if ($callerIdName !== '' && $callerIdName !== 'Outbound Call') {
            return $callerIdName;
        }

        return $callerIdNumber !== '' ? $callerIdNumber : '—';
    }

    protected function looksLikeSipUsername(string $value): bool
    {
        $value = trim($value);

        return $value !== '' && preg_match('/[a-z]/i', $value) === 1;
    }

    protected function resolveOutboundDidForCdr(string $agentExtension, string $sipUsername): string
    {
        if ($agentExtension !== '') {
            $did = $this->extensionOutboundDidE164($agentExtension);

            if ($did !== '') {
                return $did;
            }
        }

        $mapped = $this->sipUsernameOutboundDidMap()[strtolower(trim($sipUsername))] ?? '';

        if ($mapped !== '') {
            return $this->formatDidE164($mapped);
        }

        return $this->formatConfiguredOutboundDid();
    }

    /**
     * @return array<string, string> lowercase sip username => did digits
     */
    protected function sipUsernameOutboundDidMap(): array
    {
        if ($this->memoizedSipUsernameDidMap !== null) {
            return $this->memoizedSipUsernameDidMap;
        }

        $userIdToUsername = [];
        foreach ($this->listUsers(['limit' => 500])['users'] ?? [] as $user) {
            $id = (string) ($user['id'] ?? '');
            $username = strtolower(trim((string) ($user['username'] ?? '')));

            if ($id !== '' && $username !== '') {
                $userIdToUsername[$id] = $username;
            }
        }

        $map = [];
        foreach ($this->listExtensions(['limit' => 500])['extensions'] ?? [] as $ext) {
            $userId = (string) ($ext['user_id'] ?? '');
            $username = $userIdToUsername[$userId] ?? '';
            $did = preg_replace('/\D/', '', (string) ($ext['outbound_cid_num'] ?? $ext['caller_id_num'] ?? '')) ?? '';

            if ($username !== '' && $did !== '') {
                $map[$username] = $did;
            }
        }

        return $this->memoizedSipUsernameDidMap = $map;
    }

    protected function extensionOutboundDidE164(string $extensionNum): string
    {
        $normalized = preg_replace('/\D/', '', $extensionNum) ?: $extensionNum;

        if ($this->memoizedExtensionDidMap === null) {
            $this->memoizedExtensionDidMap = [];
            foreach ($this->listExtensions(['limit' => 500])['extensions'] ?? [] as $ext) {
                $num = (string) ($ext['extension_num'] ?? '');
                $did = preg_replace('/\D/', '', (string) ($ext['outbound_cid_num'] ?? $ext['caller_id_num'] ?? '')) ?? '';

                if ($num !== '' && $did !== '') {
                    $this->memoizedExtensionDidMap[$num] = $did;
                }
            }
        }

        $digits = $this->memoizedExtensionDidMap[$normalized] ?? '';

        return $digits !== '' ? $this->formatDidE164($digits) : '';
    }

    protected function formatDidE164(string $digits): string
    {
        $digits = preg_replace('/\D/', '', $digits) ?? '';

        if ($digits === '') {
            return '';
        }

        if (strlen($digits) === 10) {
            return '+1'.$digits;
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return '+'.$digits;
        }

        return '+'.$digits;
    }

    protected function formatConfiguredOutboundDid(): string
    {
        $raw = (string) (config('integrations.communications.default_outbound_did') ?? '');
        $digits = preg_replace('/\D/', '', $raw) ?? '';

        return $digits !== '' ? $this->formatDidE164($digits) : '';
    }

    protected function cdrTimestamp(?string $date, bool $endOfDay = false): ?string
    {
        if (! filled($date)) {
            return null;
        }

        try {
            $parsed = \Carbon\Carbon::parse($date);

            return ($endOfDay ? $parsed->endOfDay() : $parsed->startOfDay())->toIso8601String();
        } catch (\Throwable) {
            return $date;
        }
    }

    /**
     * Normalize a call row for the communications hub (idempotent for listCallLogs output).
     *
     * @return array<string, mixed>
     */
    public function normalizeCallLog(array $row): array
    {
        if (isset($row['from']) && isset($row['direction'])) {
            return array_merge($row, ['raw' => $row['raw'] ?? $row]);
        }

        return [
            'id' => (string) ($row['uuid'] ?? $row['id'] ?? ''),
            'direction' => $row['direction'] ?? 'inbound',
            'from' => $row['caller_name'] ?? $row['phone_number'] ?? '—',
            'to' => $row['callee_name'] ?? '—',
            'from_phone' => $row['phone_number'] ?? '',
            'to_phone' => $row['phone_number'] ?? '',
            'start_time' => $row['started_at'] ?? null,
            'result' => ($row['status'] ?? '') === 'active' ? 'Active Call' : ($row['status'] ?? '—'),
            'duration' => (int) ($row['duration'] ?? 0),
            'recording' => '—',
            'campaign_id' => $row['campaign_id'] ?? null,
            'raw' => $row,
        ];
    }

    public function compactZoomReferenceId(string $id): string
    {
        return preg_replace('/[^a-zA-Z0-9]/', '', $id) ?? $id;
    }

    /**
     * @return array{recordings: array<int, array<string, mixed>>, next_page_token: null}
     */
    public function listPhoneRecordings(array $filters = []): array
    {
        return $this->listRecordings($filters);
    }

    /**
     * @return array{messages: array<int, array<string, mixed>>, next_page_token: null}
     */
    public function getSmsSessionMessages(string $sessionId, array $filters = []): array
    {
        return app(MorpheusPlatformApiService::class)->getSmsSessionMessages($sessionId, $filters);
    }

    /**
     * @return array{success: bool, error?: string}
     */
    public function sendSmsMessage(array $payload): array
    {
        return ['success' => false, 'error' => 'Morpheus CX does not support SMS via the Call-Control API.'];
    }

    /**
     * @return array{messages: array<int, array<string, mixed>>, next_page_token: null}
     */
    public function getTeamChatMessages(string $ownerUserId, array $filters = []): array
    {
        return app(MorpheusPlatformApiService::class)->getTeamChatMessages($ownerUserId, $filters);
    }

    protected function platformApi(): MorpheusPlatformApiService
    {
        return app(MorpheusPlatformApiService::class);
    }

    // =========================================================================
    // Stub methods — features not available in the Morpheus CX Call-Control API
    // =========================================================================

    public function listRecordings(array $filters = []): array
    {
        $warnings = [];

        try {
            $response = $this->client()->get($this->url('/recordings'), array_filter([
                'from' => $this->cdrTimestamp($filters['from'] ?? null),
                'to' => $this->cdrTimestamp($filters['to'] ?? null, true),
                'direction' => $filters['direction'] ?? null,
                'search' => $filters['search'] ?? null,
                'call_uuid' => $filters['call_uuid'] ?? null,
                'limit' => $filters['per_page'] ?? 50,
                'offset' => is_numeric($filters['page_token'] ?? null) ? (int) $filters['page_token'] : 0,
            ], fn ($value) => $value !== null && $value !== ''));

            if ($response->status() === 403) {
                return [
                    'recordings' => [],
                    'next_page_token' => null,
                    'warnings' => ['API key lacks recordings:read permission.'],
                ];
            }

            if (! $response->successful()) {
                $platform = $this->platformApi()->listRecordings($filters);
                if (filled($platform['warning'] ?? null)) {
                    $warnings[] = $platform['warning'];
                }

                return [
                    'recordings' => $this->normalizeMorpheusRecordings($platform['recordings'] ?? []),
                    'next_page_token' => null,
                    'warnings' => $warnings,
                ];
            }

            $rows = $response->json('recordings') ?? [];

            return [
                'recordings' => $this->normalizeMorpheusRecordings(is_array($rows) ? $rows : []),
                'next_page_token' => null,
                'warnings' => $warnings,
            ];
        } catch (\Throwable $e) {
            return ['recordings' => [], 'next_page_token' => null, 'warnings' => [$e->getMessage()]];
        }
    }

    public function listVoiceMails(array $filters = []): array
    {
        $status = $filters['status'] ?? null;
        if ($status === 'unread') {
            $status = 'new';
        }

        try {
            $response = $this->client()->get($this->url('/voicemails'), array_filter([
                'extension_id' => $filters['extension_id'] ?? null,
                'status' => $status,
                'limit' => $filters['per_page'] ?? 50,
                'offset' => is_numeric($filters['page_token'] ?? null) ? (int) $filters['page_token'] : 0,
            ], fn ($value) => $value !== null && $value !== ''));

            if ($response->status() === 403) {
                return [
                    'voice_mails' => [],
                    'next_page_token' => null,
                    'warning' => 'API key lacks voicemails:read permission.',
                ];
            }

            if (! $response->successful()) {
                $platform = $this->platformApi()->listVoiceMails($filters);

                return [
                    'voice_mails' => $this->normalizeMorpheusVoiceMails($platform['voice_mails'] ?? []),
                    'next_page_token' => null,
                    'warning' => $platform['warning'] ?? null,
                ];
            }

            $rows = $response->json('voicemails') ?? [];

            return [
                'voice_mails' => $this->normalizeMorpheusVoiceMails(is_array($rows) ? $rows : []),
                'next_page_token' => null,
                'warning' => null,
            ];
        } catch (\Throwable $e) {
            return ['voice_mails' => [], 'next_page_token' => null, 'warning' => $e->getMessage()];
        }
    }

    public function listSmsSessions(array $filters = []): array
    {
        $result = $this->platformApi()->listSmsSessions($filters);

        return [
            'sessions' => $this->normalizePlatformSmsSessions($result['sessions'] ?? []),
            'next_page_token' => null,
            'warning' => $result['warning'] ?? null,
        ];
    }

    public function listCallQueues(array $filters = []): array
    {
        // Alias to listQueues() but returns hub-compatible key
        $result = $this->listQueues();
        return ['queues' => $result['queues'] ?? [], 'next_page_token' => null, 'warning' => null];
    }

    public function listTeamChatChannels(array $filters = []): array
    {
        return $this->platformApi()->listTeamChatChannels($filters);
    }

    public function sendTeamChatMessage(string $userId, array $payload): array
    {
        if (! $this->isConfigured()) {
            return ['success' => false, 'error' => 'Morpheus CX is not configured.'];
        }

        try {
            $channelId = $payload['channel_id'] ?? $payload['to_channel'] ?? null;
            $path = $channelId ? "/chat/{$channelId}/messages" : '/chat/messages';
            $response = Http::timeout((int) config('integrations.communications.http_timeout_seconds', 12))
                ->acceptJson()
                ->withHeaders([
                    'X-API-Key' => (string) (config('integrations.morpheus.platform_api_key')
                        ?: config('integrations.morpheus.api_key')),
                ])
                ->post(
                    'https://'.config('integrations.morpheus.host').'/api/v1'.$path,
                    ['message' => $payload['message'] ?? $payload['body'] ?? '']
                );

            if ($response->successful()) {
                return ['success' => true, 'message' => $response->json()];
            }

            return [
                'success' => false,
                'error' => (string) ($response->json('error') ?? 'Could not send chat message.'),
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeMorpheusRecordings(array $rows): array
    {
        return collect($rows)->map(function (array $row) {
            $id = (string) ($row['id'] ?? '');
            $caller = $row['caller_id_number'] ?? $row['caller_id_name'] ?? 'Caller';
            $dest = $row['destination_number'] ?? 'Callee';
            $agent = $row['agent'] ?? $row['extension'] ?? null;

            return [
                'id' => $id,
                'topic' => trim(($row['direction'] ?? 'call').' · '.$caller.' → '.$dest),
                'source' => 'phone',
                'file_type' => 'audio/wav',
                'start_time' => $row['created_at'] ?? $row['finalized_at'] ?? null,
                'duration' => (int) ($row['duration_sec'] ?? 0),
                'host' => $agent ?? $caller,
                'has_media' => $id !== '',
                'call_uuid' => $row['call_uuid'] ?? null,
                'call_history_uuid' => $row['call_uuid'] ?? null,
                'call_id' => $row['call_uuid'] ?? null,
                'download_url' => $row['download_url'] ?? null,
                'raw' => $row,
            ];
        })->values()->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeMorpheusVoiceMails(array $rows): array
    {
        return collect($rows)->map(function (array $row) {
            $id = (string) ($row['id'] ?? '');
            $status = $row['status'] ?? 'new';

            return [
                'id' => $id,
                'file_id' => $id,
                'caller' => $row['caller_id_name'] ?? $row['caller_id_number'] ?? 'Unknown',
                'caller_number' => $row['caller_id_number'] ?? '',
                'callee' => $row['extension'] ?? '—',
                'date_time' => $row['created_at'] ?? null,
                'duration' => (int) ($row['duration_sec'] ?? 0),
                'status' => $status === 'new' ? 'unread' : $status,
                'has_media' => $id !== '',
                'download_url' => $row['download_url'] ?? null,
                'raw' => $row,
            ];
        })->values()->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function normalizePlatformRecordings(array $rows): array
    {
        return $this->normalizeMorpheusRecordings($rows);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function normalizePlatformVoiceMails(array $rows): array
    {
        return $this->normalizeMorpheusVoiceMails($rows);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function normalizePlatformSmsSessions(array $rows): array
    {
        return collect($rows)->map(function (array $row) {
            $sessionId = (string) ($row['session_id'] ?? $row['id'] ?? '');

            return [
                'session_id' => $sessionId,
                'phone_number' => $row['phone_number'] ?? $row['to'] ?? $row['from'] ?? '',
                'contact_name' => $row['contact_name'] ?? $row['name'] ?? null,
                'last_message' => $row['last_message'] ?? $row['preview'] ?? '',
                'last_message_at' => $row['last_message_at'] ?? $row['updated_at'] ?? null,
                'unread_count' => (int) ($row['unread_count'] ?? 0),
                'raw' => $row,
            ];
        })->values()->all();
    }

    /**
     * Stream recording audio from the Morpheus platform API when available.
     */
    public function streamRecording(string $source, string $recordingId, bool $download = false, ?string $callReferenceId = null): \Symfony\Component\HttpFoundation\Response
    {
        $response = $this->authenticatedMediaGet($this->url("/recordings/{$recordingId}/download"));

        if (! $response->successful()) {
            throw new \RuntimeException('Recording not found.');
        }

        $contentType = $response->header('Content-Type') ?: 'audio/wav';
        $disposition = $download ? 'attachment' : 'inline';

        return response($response->body(), 200, [
            'Content-Type' => $contentType,
            'Content-Disposition' => "{$disposition}; filename=\"recording-{$recordingId}.wav\"",
        ]);
    }

    public function streamVoicemail(string $fileId, bool $download = false): \Symfony\Component\HttpFoundation\Response
    {
        $query = $download ? '' : '?mark_read=1';
        $path = "/voicemails/{$fileId}/download".$query;
        $response = $this->authenticatedMediaGet($this->url($path));

        if (! $response->successful()) {
            throw new \RuntimeException('Voicemail not found.');
        }

        $contentType = $response->header('Content-Type') ?: 'audio/wav';
        $disposition = $download ? 'attachment' : 'inline';

        return response($response->body(), 200, [
            'Content-Type' => $contentType,
            'Content-Disposition' => "{$disposition}; filename=\"voicemail-{$fileId}.wav\"",
        ]);
    }

    protected function authenticatedMediaGet(string $url): \Illuminate\Http\Client\Response
    {
        app(MorpheusCircuitBreaker::class)->guard();

        return Http::connectTimeout(2)
            ->timeout((int) config('integrations.communications.http_timeout_seconds', 6))
            ->withHeaders($this->morpheusAuthHeaders())
            ->get($url);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function morpheusAuthHeaders(): array
    {
        $apiKey = trim((string) config('integrations.morpheus.api_key'));

        if ($apiKey === '') {
            return [];
        }

        return [
            'X-API-Key' => $apiKey,
            'Authorization' => 'Bearer '.$apiKey,
        ];
    }

    private function client(): PendingRequest
    {
        app(MorpheusCircuitBreaker::class)->guard();

        return Http::withHeaders($this->morpheusAuthHeaders())
            ->acceptJson()
            ->connectTimeout(2)
            ->timeout((int) config('integrations.communications.http_timeout_seconds', 6));
    }

    private function pollClient(): PendingRequest
    {
        app(MorpheusCircuitBreaker::class)->guard();

        return Http::withHeaders($this->morpheusAuthHeaders())
            ->acceptJson()
            ->connectTimeout(2)
            ->timeout(3);
    }

    private function url(string $path): string
    {
        $host = rtrim(config('integrations.morpheus.host'), '/');
        return "https://{$host}/api/v1/call-control" . $path;
    }

    /**
     * Generic call-action helper for all POST /calls/{uuid}/{action} endpoints.
     */
    private function callAction(string $uuid, string $action, array $body = []): array
    {
        try {
            $request = $this->client();
            $url = $this->url("/calls/{$uuid}/{$action}");
            $response = empty($body) ? $request->post($url) : $request->post($url, $body);

            if ($response->successful()) {
                return array_merge(['ok' => true, 'action' => $action], $response->json() ?? []);
            }
            return [
                'ok'     => false,
                'action' => $action,
                'error'  => $response->json('error') ?? 'HTTP ' . $response->status(),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'action' => $action, 'error' => $e->getMessage()];
        }
    }

    private function isCallAlreadyEndedError(string $error): bool
    {
        $normalized = strtolower(trim($error));

        if ($normalized === '') {
            return false;
        }

        return str_contains($normalized, 'call not found')
            || str_contains($normalized, 'not found')
            || str_contains($normalized, 'no such call')
            || str_contains($normalized, 'already ended')
            || str_contains($normalized, 'http 404');
    }
}
