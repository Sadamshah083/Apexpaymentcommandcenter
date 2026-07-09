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

        $state = $this->normalizeState([
            'uuid' => $uuid,
            'from_extension' => $this->digits($fromExtension),
            'destination' => $this->digits($destination),
            'destination_answered' => false,
            'destination_connected' => false,
            'live' => true,
            'outcome' => 'initiated',
            'source' => 'watch',
            'updated_at' => now()->toIso8601String(),
        ]);

        $this->putState($uuid, $state);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function ingestWebhook(array $payload): array
    {
        $uuids = $this->extractUuids($payload);
        $eventName = $this->extractEventName($payload);
        $destinationDigits = $this->extractDestinationDigits($payload);
        $extensionDigits = $this->extractExtensionDigits($payload);
        $sipCode = $this->extractSipCode($payload);
        $billsec = (int) ($this->dig($payload, ['billsec', 'duration_sec', 'talk_seconds']) ?? 0);
        $bridgedTo = $this->stringOrNull($this->digAny($payload, [
            ['bridged_to'], ['bridge_uuid'], ['other_leg_uuid'],
            ['data', 'bridged_to'], ['data', 'bridge_uuid'], ['data', 'other_leg_uuid'],
            ['payload', 'bridged_to'], ['payload', 'bridge_uuid'], ['payload', 'other_leg_uuid'],
            ['call', 'bridged_to'], ['call', 'bridge_uuid'], ['call', 'other_leg_uuid'],
        ]));

        $destinationAnswered = $this->payloadIndicatesDestinationAnswered(
            $payload,
            $eventName,
            $sipCode,
            $billsec,
            $destinationDigits,
        );

        $hangup = $this->payloadIndicatesHangup($payload, $eventName);
        if (! $hangup && $bridgedTo !== null) {
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

        $bridgedLive = ($state['live'] ?? false) && ! empty($state['bridged_to']);
        if (! ($state['destination_answered'] ?? false) && ! $bridgedLive) {
            return [];
        }

        return array_filter([
            'destination_connected' => true,
            'destination_answered' => true,
            'outcome' => 'connected',
            'state' => 'CONNECTED',
            'live' => $state['live'] ?? true,
            'billsec' => $state['billsec'] ?? null,
            'bridged_to' => $state['bridged_to'] ?? null,
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
        foreach (['event', 'event_type', 'type', 'name', 'action'] as $key) {
            $value = $payload[$key] ?? $payload['data'][$key] ?? $payload['payload'][$key] ?? null;
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

        if ($sipCode === 200 && strlen($destinationDigits) >= 10) {
            return true;
        }

        if ($billsec >= 1 && strlen($destinationDigits) >= 10) {
            return true;
        }

        if ($eventName !== '' && strlen($destinationDigits) >= 10) {
            foreach (['answered', 'connected', 'bridge', 'active', 'talking', 'established'] as $needle) {
                if (str_contains($eventName, $needle) && ! str_contains($eventName, 'unanswered')) {
                    return true;
                }
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

        return $eventName !== '' && (
            str_contains($eventName, 'hangup')
            || str_contains($eventName, 'ended')
            || str_contains($eventName, 'completed')
        );
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

    protected function destinationKey(string $destinationDigits): string
    {
        return 'integrations.morpheus.call_dest.'.$destinationDigits;
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
