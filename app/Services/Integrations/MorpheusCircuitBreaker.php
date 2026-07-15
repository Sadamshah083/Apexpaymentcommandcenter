<?php

namespace App\Services\Integrations;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;

/**
 * Isolates Morpheus telephony failures from the rest of ApexOne.
 * When Morpheus is unreachable, the circuit opens and API calls fail fast
 * without blocking PHP workers on long HTTP timeouts.
 */
class MorpheusCircuitBreaker
{
    private const OPEN_KEY = 'integrations.morpheus.circuit_open';

    public function isOpen(): bool
    {
        try {
            return Cache::has(self::OPEN_KEY);
        } catch (\Throwable) {
            // Never block dialing if the cache store is misconfigured.
            return false;
        }
    }

    public function trip(?int $seconds = null): void
    {
        $seconds ??= (int) config('integrations.morpheus.circuit_breaker_seconds', 120);

        try {
            Cache::put(self::OPEN_KEY, true, $seconds);
        } catch (\Throwable) {
            //
        }
    }

    public function reset(): void
    {
        try {
            Cache::forget(self::OPEN_KEY);
        } catch (\Throwable) {
            //
        }
    }

    public function recordSuccess(): void
    {
        try {
            if ($this->isOpen()) {
                $this->reset();
            }
        } catch (\Throwable) {
            //
        }
    }

    public function unavailableMessage(): string
    {
        return 'Morpheus telephony is temporarily unavailable. The rest of ApexOne is unaffected.';
    }

    public function guard(): void
    {
        if ($this->isOpen()) {
            throw new \RuntimeException($this->unavailableMessage());
        }
    }

    public function reportFailure(\Throwable $exception): void
    {
        if ($exception instanceof ConnectionException || $this->looksLikeConnectivityFailure($exception)) {
            $this->trip();
        }
    }

    protected function looksLikeConnectivityFailure(\Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'timed out')
            || str_contains($message, 'timeout')
            || str_contains($message, 'connection refused')
            || str_contains($message, 'could not resolve')
            || str_contains($message, 'curl error 28')
            || str_contains($message, 'failed to connect');
    }
}
