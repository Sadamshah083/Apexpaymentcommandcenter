<?php

namespace App\Services\Communications;

use App\Services\Integrations\ZoomApiService;
use Illuminate\Support\Facades\Cache;

class MorpheusHubService
{
    public function __construct(
        protected ZoomApiService $api,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function activeCalls(): array
    {
        return $this->remember('active_calls', fn () => $this->api->listCalls()['calls'] ?? []);
    }

    /**
     * Bypass the long hub cache — used by Call Monitoring for near-real-time state.
     *
     * @return array<int, array<string, mixed>>
     */
    public function activeCallsFresh(): array
    {
        try {
            // Use the 2s poll client path — never block page navigations on Morpheus.
            $calls = $this->api->listCallsFast()['calls'] ?? [];

            return is_array($calls) ? $calls : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function queues(): array
    {
        return $this->remember('queues', fn () => $this->api->listQueues()['queues'] ?? []);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function conferences(): array
    {
        return $this->remember('conferences', fn () => $this->api->listConferences()['conferences'] ?? []);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function leads(array $filters = []): array
    {
        $key = 'leads.'.md5(json_encode($filters));

        return $this->remember($key, fn () => $this->api->listLeads($filters)['leads'] ?? []);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function campaigns(array $filters = []): array
    {
        $key = 'campaigns.'.md5(json_encode($filters));

        return $this->remember($key, fn () => $this->api->listCampaigns($filters)['campaigns'] ?? []);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function lists(array $filters = []): array
    {
        $key = 'lists.'.md5(json_encode($filters));

        return $this->remember($key, fn () => $this->api->listLeadLists($filters)['lists'] ?? []);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function users(): array
    {
        return $this->remember('users', fn () => $this->api->listUsers()['users'] ?? []);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function extensions(): array
    {
        return $this->remember('extensions', fn () => $this->api->listExtensions()['extensions'] ?? []);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function queueWaiting(string $queueId): array
    {
        return $this->api->getQueueWaiting($queueId)['waiting'] ?? [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function conferenceMembers(string $conferenceId): array
    {
        $payload = $this->api->getConferenceMembers($conferenceId);

        return $payload['members'] ?? [];
    }

    public function bustCache(): void
    {
        $version = (int) Cache::get('morpheus.hub.cache_version', 1);
        Cache::forever('morpheus.hub.cache_version', $version + 1);
    }

    /**
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    protected function remember(string $suffix, callable $callback): mixed
    {
        $version = (int) Cache::get('morpheus.hub.cache_version', 1);
        $key = "morpheus.hub.v{$version}.{$suffix}";
        $failureKey = "{$key}.failure";
        $ttl = now()->addMinutes((int) config('integrations.communications.cache_ttl_minutes', 10));

        $cached = Cache::get($key);
        if ($cached !== null) {
            return $cached;
        }

        if (Cache::has($failureKey)) {
            return [];
        }

        try {
            $value = $callback();
        } catch (\Throwable $e) {
            app(\App\Services\Integrations\MorpheusCircuitBreaker::class)->reportFailure($e);
            Cache::put($failureKey, true, now()->addSeconds(90));

            return [];
        }

        Cache::put($key, $value, $ttl);

        return $value;
    }
}
