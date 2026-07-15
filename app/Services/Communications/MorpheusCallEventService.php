<?php

namespace App\Services\Communications;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class MorpheusCallEventService
{
    public const CACHE_TTL_SECONDS = 7200;

    /**
     * Track an outbound attempt so webhook payloads can be correlated.
     */
    public function watchCall(string $uuid, ?string $fromExtension = null, ?string $destination = null): void
    {
        $uuid = trim($uuid);
        if ($uuid === '') {
            return;
        }

        $fromDigits = $this->digits($fromExtension);
        $destDigits = $this->digits($destination);
        $this->supersedeLiveCallsForLeg($fromDigits, $destDigits, $uuid);

        $state = $this->normalizeState([
            'uuid' => $uuid,
            'from_extension' => $fromDigits,
            'destination' => $destDigits,
            'destination_answered' => false,
            'destination_connected' => false,
            'live' => true,
            'outcome' => 'initiated',
            'source' => 'watch',
            'updated_at' => now()->toIso8601String(),
        ]);

        $this->putState($uuid, $state);

        if ($destDigits !== '') {
            Cache::put($this->destinationWatchKey($destDigits), $uuid, self::CACHE_TTL_SECONDS);
        }
    }

    /**
     * Keep one live watch per agent+destination so wallboard does not stack ringing rows.
     */
    protected function supersedeLiveCallsForLeg(string $fromDigits, string $destDigits, string $keepUuid): void
    {
        if ($fromDigits === '' && $destDigits === '') {
            return;
        }

        foreach ($this->listLiveStates() as $state) {
            $existingUuid = trim((string) ($state['uuid'] ?? ''));
            if ($existingUuid === '' || $existingUuid === $keepUuid) {
                continue;
            }

            $sameExt = $fromDigits === '' || $this->digits((string) ($state['from_extension'] ?? '')) === $fromDigits;
            $sameDest = $destDigits === '' || $this->digits((string) ($state['destination'] ?? '')) === $destDigits;
            if (! $sameExt || ! $sameDest) {
                continue;
            }

            $this->markCallEnded($existingUuid, 'SUPERSEDED', isset($state['billsec']) ? (int) $state['billsec'] : null);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function ingestWebhook(array $payload): array
    {
        $bridgedTo = $this->stringOrNull($this->digAny($payload, [
            ['bridged_to'], ['bridge_uuid'], ['other_leg_uuid'],
            ['data', 'bridged_to'], ['data', 'bridge_uuid'], ['data', 'other_leg_uuid'],
            ['payload', 'bridged_to'], ['payload', 'bridge_uuid'], ['payload', 'other_leg_uuid'],
            ['call', 'bridged_to'], ['call', 'bridge_uuid'], ['call', 'other_leg_uuid'],
        ]));

        $destinationDigits = $this->extractDestinationDigits($payload);
        $extensionDigits = $this->extractExtensionDigits($payload);
        $sipCode = $this->extractSipCode($payload);
        $billsec = (int) ($this->dig($payload, ['billsec', 'duration_sec', 'talk_seconds']) ?? 0);

        $uuids = $this->extractUuids($payload);
        if ($uuids === [] && $destinationDigits !== '') {
            $watchedUuid = Cache::get($this->destinationWatchKey($destinationDigits));
            if (is_string($watchedUuid) && trim($watchedUuid) !== '') {
                $uuids[] = trim($watchedUuid);
            }
        }

        if ($bridgedTo !== null && ! in_array($bridgedTo, $uuids, true)) {
            $uuids[] = $bridgedTo;
        }

        $eventName = $this->extractEventName($payload);

        $destinationAnswered = $this->payloadIndicatesDestinationAnswered(
            $payload,
            $eventName,
            $sipCode,
            $billsec,
            $destinationDigits,
        );

        $hangup = $this->payloadIndicatesHangup($payload, $eventName);

        // Real-time bridge/answer: bridged B-leg or explicit answer event.
        if (! $hangup && $bridgedTo !== null && ($billsec >= 1 || $this->eventLooksLikeAnswer($eventName))) {
            $destinationAnswered = true;
        }

        if (! $hangup && ! $destinationAnswered && $this->eventLooksLikeAnswer($eventName) && strlen($destinationDigits) >= 10) {
            $destinationAnswered = true;
        }

        $normalized = $this->normalizeState([
            'uuid' => $uuids[0] ?? null,
            'related_uuids' => $uuids,
            'event' => $eventName,
            'destination' => $destinationDigits,
            'from_extension' => $extensionDigits,
            'destination_answered' => $destinationAnswered,
            'destination_connected' => $destinationAnswered,
            'live' => ! $hangup,
            'outcome' => $destinationAnswered ? 'connected' : ($hangup ? 'ended' : 'ringing'),
            'sip_code' => $sipCode,
            'billsec' => $billsec,
            'bridged_to' => $bridgedTo,
            'hangup_cause' => $this->stringOrNull($this->digAny($payload, [
                ['hangup_cause'], ['cause'],
                ['data', 'hangup_cause'], ['data', 'cause'],
                ['payload', 'hangup_cause'], ['payload', 'cause'],
                ['call', 'hangup_cause'], ['call', 'cause'],
            ])),
            'source' => 'webhook',
            'updated_at' => now()->toIso8601String(),
        ]);

        foreach ($uuids as $uuid) {
            $existing = $this->getCallState($uuid) ?? [];
            $merged = $this->normalizeState(array_merge($existing, $normalized, [
                'uuid' => $uuid,
            ]));

            if ($destinationAnswered) {
                $merged['destination_answered'] = true;
                $merged['destination_connected'] = true;
                $merged['outcome'] = 'connected';
                $merged['live'] = true;
                if (empty($existing['connected_at'])) {
                    $merged['connected_at'] = now()->toIso8601String();
                } else {
                    $merged['connected_at'] = $existing['connected_at'];
                }
            }

            if ($hangup) {
                $merged['live'] = false;
                $merged['outcome'] = 'ended';
            }

            $this->putState($uuid, $merged);
        }

        if ($destinationDigits !== '') {
            Cache::put($this->destinationKey($destinationDigits), $normalized, self::CACHE_TTL_SECONDS);
        }

        return $normalized;
    }

    public function markCallEnded(string $uuid, ?string $hangupCause = null, ?int $billsec = null): void
    {
        $uuid = trim($uuid);
        if ($uuid === '') {
            return;
        }

        $existing = $this->getCallState($uuid) ?? [];
        $merged = $this->normalizeState(array_merge($existing, [
            'uuid' => $uuid,
            'live' => false,
            'outcome' => 'ended',
            'destination_connected' => false,
            'destination_answered' => false,
            'hangup_cause' => $this->stringOrNull($hangupCause),
            'billsec' => $billsec,
            'source' => 'hangup',
            'updated_at' => now()->toIso8601String(),
        ]));

        $this->putState($uuid, $merged);

        $destDigits = $this->digits((string) ($merged['destination'] ?? $existing['destination'] ?? ''));
        if ($destDigits !== '') {
            Cache::put($this->destinationKey($destDigits), $merged, self::CACHE_TTL_SECONDS);
        }
    }

    /**
     * End every live watch for an agent leg (used on hangup / release).
     */
    public function endLiveCallsForLeg(?string $fromExtension = null, ?string $destination = null, string $hangupCause = 'NORMAL_CLEARING'): int
    {
        $fromDigits = $this->digits($fromExtension);
        $destDigits = $this->digits($destination);
        $ended = 0;

        foreach ($this->listLiveStates() as $state) {
            $uuid = trim((string) ($state['uuid'] ?? ''));
            if ($uuid === '') {
                continue;
            }

            $sameExt = $fromDigits === '' || $this->digits((string) ($state['from_extension'] ?? '')) === $fromDigits;
            $sameDest = $destDigits === '' || $this->digits((string) ($state['destination'] ?? '')) === $destDigits;
            if (! $sameExt || ! $sameDest) {
                continue;
            }

            $this->markCallEnded($uuid, $hangupCause, isset($state['billsec']) ? (int) $state['billsec'] : null);
            $ended++;
        }

        return $ended;
    }

    /**
     * Drop abandoned ringing rows. Connected calls are ended only by hangup/release —
     * do not auto-hide mid-call (that made timers disappear after ~60s).
     */
    public function pruneStaleLiveStates(int $ringMaxSec = 120, int $connectedIdleSec = 0): int
    {
        $ended = 0;
        foreach ($this->listLiveStates() as $state) {
            $uuid = trim((string) ($state['uuid'] ?? ''));
            if ($uuid === '') {
                continue;
            }

            $updatedAt = $state['updated_at'] ?? null;
            $updatedAge = 0;
            if (filled($updatedAt)) {
                try {
                    $updatedAge = max(0, \Carbon\Carbon::parse((string) $updatedAt)->diffInSeconds(now()));
                } catch (\Throwable) {
                    $updatedAge = 0;
                }
            }

            $connected = (bool) ($state['destination_answered'] ?? false)
                || (bool) ($state['destination_connected'] ?? false);

            if (! $connected && $updatedAge >= $ringMaxSec) {
                $this->markCallEnded($uuid, 'STALE_RING', isset($state['billsec']) ? (int) $state['billsec'] : null);
                $ended++;
                continue;
            }

            // Optional safety for extreme zombies only (disabled when $connectedIdleSec <= 0).
            if ($connected && $connectedIdleSec > 0 && $updatedAge >= $connectedIdleSec) {
                $this->markCallEnded($uuid, 'STALE_CONNECTED', isset($state['billsec']) ? (int) $state['billsec'] : null);
                $ended++;
            }
        }

        return $ended;
    }

    /**
     * Persist both-sides-connected for wallboard / dialer (webhooks often miss this).
     */
    public function markDestinationConnected(
        string $uuid,
        ?string $destination = null,
        ?int $billsec = null,
        string $source = 'agent',
        ?string $connectedAt = null,
    ): void {
        $uuid = trim($uuid);
        if ($uuid === '') {
            return;
        }

        $existing = $this->getCallState($uuid) ?? [];
        if (($existing['live'] ?? true) === false && filled($existing['hangup_cause'] ?? null)) {
            return;
        }

        $resolvedConnectedAt = $existing['connected_at'] ?? null;
        // Dialer connected timer is source of truth — prefer client connected_at.
        // Once set, keep the earliest epoch so monitoring and dialer seconds stay matched.
        if (filled($connectedAt)) {
            try {
                $incoming = \Carbon\Carbon::parse($connectedAt)->utc();
                if (filled($resolvedConnectedAt)) {
                    $existingAt = \Carbon\Carbon::parse((string) $resolvedConnectedAt)->utc();
                    $resolvedConnectedAt = $incoming->lte($existingAt)
                        ? $incoming->toIso8601String()
                        : $existingAt->toIso8601String();
                } else {
                    $resolvedConnectedAt = $incoming->toIso8601String();
                }
            } catch (\Throwable) {
                if (! filled($resolvedConnectedAt)) {
                    $resolvedConnectedAt = now()->utc()->toIso8601String();
                }
            }
        }
        if (! filled($resolvedConnectedAt)) {
            $resolvedConnectedAt = now()->utc()->toIso8601String();
        }

        $destDigits = $this->digits($destination) ?: (string) ($existing['destination'] ?? '');
        $fromExtension = (string) ($existing['from_extension'] ?? '');
        $merged = $this->normalizeState(array_merge($existing, [
            'uuid' => $uuid,
            'destination' => $destDigits !== '' ? $destDigits : ($existing['destination'] ?? null),
            'from_extension' => $fromExtension !== '' ? $fromExtension : ($existing['from_extension'] ?? null),
            'destination_answered' => true,
            'destination_connected' => true,
            'live' => true,
            'outcome' => 'connected',
            'billsec' => $billsec ?? ($existing['billsec'] ?? null),
            'connected_at' => $resolvedConnectedAt,
            'source' => $source,
            'updated_at' => now()->toIso8601String(),
        ]));

        $this->putState($uuid, $merged);

        if ($destDigits !== '') {
            Cache::put($this->destinationKey($destDigits), $merged, self::CACHE_TTL_SECONDS);
            Cache::put($this->destinationWatchKey($destDigits), $uuid, self::CACHE_TTL_SECONDS);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getCallState(string $uuid): ?array
    {
        $uuid = trim($uuid);
        if ($uuid === '') {
            return null;
        }

        $state = Cache::get($this->stateKey($uuid));

        return is_array($state) ? $state : null;
    }

    public function destinationAnswered(string $uuid, ?string $destination = null): bool
    {
        $state = $this->getCallState($uuid);
        if ($state !== null && ($state['destination_answered'] ?? false)) {
            return true;
        }

        $destDigits = $this->digits($destination);
        if ($destDigits !== '') {
            $byDest = Cache::get($this->destinationKey($destDigits));
            if (is_array($byDest) && ($byDest['destination_answered'] ?? false)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function hubStatusOverlay(string $uuid, ?string $destination = null): array
    {
        $state = $this->getCallState($uuid);
        if ($state === null) {
            return [];
        }

        $live = (bool) ($state['live'] ?? true);
        if (! $live) {
            return array_filter([
                'call_ended' => true,
                'live' => false,
                'outcome' => 'ended',
                'hangup_cause' => $this->stringOrNull($state['hangup_cause'] ?? null),
                'billsec' => isset($state['billsec']) ? (int) $state['billsec'] : null,
                'destination_connected' => false,
                'updated_at' => $state['updated_at'] ?? null,
                'source' => 'webhook',
                'webhook_event' => $state['event'] ?? null,
            ], fn ($value) => $value !== null);
        }

        $bridgedLive = $live && ! empty($state['bridged_to']);
        // Only overlay "connected" when destination was actually answered — not merely bridged.
        if (! ($state['destination_answered'] ?? false) && ! ($state['destination_connected'] ?? false)) {
            return $bridgedLive ? array_filter([
                'bridged_to' => $state['bridged_to'] ?? null,
                'live' => true,
                'outcome' => 'ringing',
                'source' => 'webhook',
                'webhook_event' => $state['event'] ?? null,
            ], fn ($value) => $value !== null) : [];
        }

        return array_filter([
            'destination_connected' => true,
            'destination_answered' => true,
            'outcome' => 'connected',
            'state' => 'CONNECTED',
            'live' => true,
            'billsec' => $state['billsec'] ?? null,
            'bridged_to' => $state['bridged_to'] ?? null,
            'connected_at' => $state['connected_at'] ?? null,
            'updated_at' => $state['updated_at'] ?? null,
            'source' => 'webhook',
            'webhook_event' => $state['event'] ?? null,
        ], fn ($value) => $value !== null);
    }

    public function verifySignature(Request $request): bool
    {
        $secret = (string) config('integrations.morpheus.webhook_secret', '');
        if ($secret === '') {
            return true;
        }

        $signature = (string) ($request->header('X-Morpheus-Signature')
            ?: $request->header('X-Webhook-Signature')
            ?: $request->header('X-Signature')
            ?: '');

        if ($signature === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $signature)
            || hash_equals($expected, str_replace('sha256=', '', $signature));
    }

    /**
     * @param  array<string, mixed>  $state
     */
    protected function putState(string $uuid, array $state): void
    {
        Cache::put($this->stateKey($uuid), $state, self::CACHE_TTL_SECONDS);
        $this->syncLiveIndex($uuid, (bool) ($state['live'] ?? false));
        $this->bumpMonitoringVersion();
    }

    /**
     * Live call states currently tracked from originate/webhooks.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listLiveStates(): array
    {
        $index = Cache::get($this->liveIndexKey(), []);
        if (! is_array($index) || $index === []) {
            return [];
        }

        $live = [];
        $stillLive = [];

        foreach ($index as $uuid) {
            $uuid = trim((string) $uuid);
            if ($uuid === '') {
                continue;
            }

            $state = $this->getCallState($uuid);
            if (! is_array($state) || ! ($state['live'] ?? false)) {
                continue;
            }

            $stillLive[] = $uuid;
            $live[] = $state;
        }

        if ($stillLive !== array_values(array_map('strval', $index))) {
            Cache::put($this->liveIndexKey(), array_values(array_unique($stillLive)), self::CACHE_TTL_SECONDS);
        }

        return $live;
    }

    public function monitoringVersion(): int
    {
        return (int) Cache::get($this->monitoringVersionKey(), 0);
    }

    public function bumpMonitoringVersion(): void
    {
        $key = $this->monitoringVersionKey();
        Cache::put($key, ((int) Cache::get($key, 0)) + 1, self::CACHE_TTL_SECONDS);
    }

    protected function syncLiveIndex(string $uuid, bool $live): void
    {
        $uuid = trim($uuid);
        if ($uuid === '') {
            return;
        }

        $index = Cache::get($this->liveIndexKey(), []);
        if (! is_array($index)) {
            $index = [];
        }

        if ($live) {
            if (! in_array($uuid, $index, true)) {
                $index[] = $uuid;
            }
        } else {
            $index = array_values(array_filter($index, fn ($item) => (string) $item !== $uuid));
        }

        Cache::put($this->liveIndexKey(), $index, self::CACHE_TTL_SECONDS);
    }

    protected function liveIndexKey(): string
    {
        return 'integrations.morpheus.live_call_uuids';
    }

    protected function monitoringVersionKey(): string
    {
        return 'integrations.morpheus.monitoring_version';
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    protected function normalizeState(array $state): array
    {
        if (isset($state['destination'])) {
            $state['destination'] = $this->digits((string) $state['destination']);
        }
        if (isset($state['from_extension'])) {
            $state['from_extension'] = $this->digits((string) $state['from_extension']);
        }

        return $state;
    }

    protected function stateKey(string $uuid): string
    {
        return 'integrations.morpheus.call_state.'.$uuid;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    protected function extractUuids(array $payload): array
    {
        $candidates = [];
        $paths = [
            ['uuid'],
            ['call_uuid'],
            ['call_id'],
            ['id'],
            ['data', 'uuid'],
            ['data', 'call_uuid'],
            ['payload', 'uuid'],
            ['payload', 'call_uuid'],
            ['call', 'uuid'],
            ['call', 'call_uuid'],
        ];

        foreach ($paths as $path) {
            $value = $this->dig($payload, $path);
            if (is_string($value) && trim($value) !== '') {
                $candidates[] = trim($value);
            }
        }

        foreach (['related_uuids', 'uuids', 'call_uuids'] as $listKey) {
            $list = $payload[$listKey] ?? $payload['data'][$listKey] ?? null;
            if (! is_array($list)) {
                continue;
            }
            foreach ($list as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $candidates[] = trim($item);
                }
            }
        }

        return array_values(array_unique($candidates));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function extractEventName(array $payload): string
    {
        foreach (['event', 'event_type', 'type', 'name', 'action', 'status', 'call_state', 'state'] as $key) {
            $value = $payload[$key] ?? $payload['data'][$key] ?? $payload['payload'][$key] ?? $payload['call'][$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return strtolower(trim($value));
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function extractSipCode(array $payload): ?int
    {
        foreach ([
            'sip_code', 'sip_response_code', 'response_code', 'sip_status',
            'data.sip_code', 'payload.sip_code',
        ] as $path) {
            $parts = explode('.', $path);
            $value = $this->dig($payload, $parts);
            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function extractDestinationDigits(array $payload): string
    {
        foreach ([
            'destination_number', 'destination', 'to', 'callee', 'dialed_number',
            'data.destination_number', 'payload.destination_number', 'call.destination_number',
        ] as $path) {
            $parts = explode('.', $path);
            $digits = $this->digits($this->dig($payload, $parts));
            if (strlen($digits) >= 10) {
                return $digits;
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function extractExtensionDigits(array $payload): string
    {
        foreach ([
            'extension', 'extension_num', 'from', 'caller', 'agent_extension',
            'data.extension', 'payload.extension',
        ] as $path) {
            $parts = explode('.', $path);
            $digits = $this->digits($this->dig($payload, $parts));
            if ($digits !== '' && strlen($digits) <= 6) {
                return $digits;
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function payloadIndicatesDestinationAnswered(
        array $payload,
        string $eventName,
        ?int $sipCode,
        int $billsec,
        string $destinationDigits,
    ): bool {
        foreach (['destination_answered', 'callee_answered', 'other_leg_answered', 'outbound_answered'] as $flag) {
            if (filter_var($this->digAny($payload, [[$flag], ['data', $flag], ['payload', $flag], ['call', $flag]]) ?? false, FILTER_VALIDATE_BOOLEAN)) {
                return true;
            }
        }

        foreach (['destination_answer_time', 'answer_time', 'answered_at', 'bridge_answer_time'] as $field) {
            if (filled($this->digAny($payload, [[$field], ['data', $field], ['payload', $field], ['call', $field]]))) {
                return true;
            }
        }

        if ($sipCode === 200 && strlen($destinationDigits) >= 10 && $billsec >= 1) {
            return true;
        }

        if ($billsec >= 1 && strlen($destinationDigits) >= 10 && $this->eventLooksLikeAnswer($eventName)) {
            return true;
        }

        if ($billsec >= 2 && strlen($destinationDigits) >= 10) {
            return true;
        }

        if ($eventName !== '' && strlen($destinationDigits) >= 10) {
            if ($this->eventLooksLikeAnswer($eventName)) {
                return true;
            }

            if (str_contains($eventName, 'ring_wait') || str_contains($eventName, 'ringing')) {
                return false;
            }
        }

        $callState = strtoupper((string) ($this->digAny($payload, [
            ['state'], ['call_state'], ['status'],
            ['data', 'state'], ['data', 'call_state'], ['data', 'status'],
            ['call', 'state'], ['call', 'call_state'], ['call', 'status'],
        ]) ?? ''));

        if (in_array($callState, ['CONNECTED', 'ANSWERED', 'BRIDGED', 'ACTIVE', 'TALKING'], true)) {
            return true;
        }

        return false;
    }

    protected function eventLooksLikeAnswer(string $eventName): bool
    {
        $eventName = strtolower(trim($eventName));
        if ($eventName === '') {
            return false;
        }

        if (str_contains($eventName, 'unanswered') || str_contains($eventName, 'no_answer') || str_contains($eventName, 'ring')) {
            return false;
        }

        foreach (['channel_answer', 'channel_bridge', 'answered', 'connected', 'bridged', 'active', 'talking', 'established', 'pickup', 'pick_up', 'callee_answer', 'destination_answer'] as $needle) {
            if (str_contains($eventName, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function payloadIndicatesHangup(array $payload, string $eventName): bool
    {
        if (filled($this->digAny($payload, [
            ['hangup_cause'], ['cause'],
            ['data', 'hangup_cause'], ['data', 'cause'],
            ['payload', 'hangup_cause'], ['payload', 'cause'],
            ['call', 'hangup_cause'], ['call', 'cause'],
        ]))) {
            return true;
        }

        $callState = strtoupper((string) ($this->digAny($payload, [
            ['state'], ['call_state'], ['status'],
            ['data', 'state'], ['data', 'call_state'], ['data', 'status'],
            ['call', 'state'], ['call', 'call_state'], ['call', 'status'],
        ]) ?? ''));

        if (in_array($callState, ['HANGUP', 'COMPLETED', 'DESTROYED', 'ENDED'], true)) {
            return true;
        }

        return $eventName !== '' && (
            str_contains($eventName, 'hangup')
            || str_contains($eventName, 'hang_up')
            || str_contains($eventName, 'channel_hangup')
            || str_contains($eventName, 'destroy')
            || str_contains($eventName, 'ended')
            || str_contains($eventName, 'completed')
            || str_contains($eventName, 'disconnected')
        ) || in_array(strtoupper($eventName), ['HANGUP', 'COMPLETED', 'DESTROYED'], true);
    }

    protected function destinationKey(string $destinationDigits): string
    {
        return 'integrations.morpheus.call_dest.'.$destinationDigits;
    }

    protected function destinationWatchKey(string $destinationDigits): string
    {
        return 'integrations.morpheus.call_dest_watch.'.$destinationDigits;
    }

    protected function digits(?string $value): string
    {
        $digits = preg_replace('/\D/', '', (string) $value) ?? '';
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return substr($digits, 1);
        }

        return $digits;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $path
     */
    protected function dig(array $payload, array $path): mixed
    {
        $cursor = $payload;
        foreach ($path as $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                return null;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<list<string>>  $paths
     */
    protected function digAny(array $payload, array $paths): mixed
    {
        foreach ($paths as $path) {
            $value = $this->dig($payload, $path);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    protected function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
