<?php

namespace App\Services\Workflow;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WorkflowProviderStatusService
{
    protected const GEMINI_ERROR_CACHE_KEY = 'workflow.gemini_last_error';

    protected const GEMINI_HEALTH_CACHE_KEY = 'workflow.gemini_health';

    public function getOpenRouterBalance(): mixed
    {
        $apiKey = config('openrouter.api_key');

        if (! $apiKey) {
            return null;
        }

        return Cache::remember('workflow.openrouter_balance', now()->addMinutes(10), function () {
            try {
                $request = Http::timeout(1.5)
                    ->withHeaders(['Authorization' => 'Bearer '.config('openrouter.api_key')]);

                if (app()->isLocal() && config('app.allow_insecure_http_in_local', false)) {
                    $request = $request->withoutVerifying();
                }

                $response = $request->get('https://openrouter.ai/api/v1/auth/key');

                if ($response->successful()) {
                    return $response->json('data.limit_remaining') ?? $response->json('data.limit');
                }
            } catch (\Throwable $e) {
                Log::debug('OpenRouter balance check failed', ['error' => $e->getMessage()]);
            }

            return null;
        });
    }

    public function getGeminiStatus(): string
    {
        $health = $this->getGeminiHealth();

        return match ($health['state']) {
            'ready' => 'Ready',
            'depleted' => 'Credits depleted',
            'invalid' => 'Invalid key',
            'error' => 'Error',
            default => config('gemini.api_key') ? 'Unknown' : 'Not Configured',
        };
    }

    /**
     * @return array{
     *     configured: bool,
     *     message: string|null,
     *     pipeline_model: string,
     *     pipeline_max_tokens: int,
     *     gemini: array<string, mixed>,
     *     openrouter: array<string, mixed>
     * }
     */
    public function getEnrichmentStatus(bool $refresh = false, bool $probe = true): array
    {
        if ($refresh) {
            Cache::forget(self::GEMINI_HEALTH_CACHE_KEY);
            Cache::forget('workflow.openrouter_balance');
        }

        $gemini = $this->getGeminiHealth($refresh || $probe);
        $openRouter = $this->getOpenRouterHealth($refresh || $probe);

        return [
            'configured' => $this->isEnrichmentConfigured(),
            'message' => $this->configurationMessage(),
            'pipeline_model' => (string) config('workflow_enrichment.gemini_model'),
            'pipeline_max_tokens' => (int) config('workflow_enrichment.gemini_max_output_tokens'),
            'gemini' => $gemini,
            'openrouter' => $openRouter,
        ];
    }

    /**
     * @return array{state: string, label: string, message: string|null, last_error: string|null, checked_at: string|null, probe_model: string|null}
     */
    public function getGeminiHealth(bool $probe = true): array
    {
        if (! filled(config('gemini.api_key'))) {
            return [
                'state' => 'not_configured',
                'label' => 'Not configured',
                'message' => null,
                'last_error' => Cache::get(self::GEMINI_ERROR_CACHE_KEY),
                'checked_at' => null,
                'probe_model' => null,
            ];
        }

        if (! $probe) {
            $cached = Cache::get(self::GEMINI_HEALTH_CACHE_KEY);
            if (is_array($cached)) {
                return $cached;
            }

            return [
                'state' => 'cached_unknown',
                'label' => 'Cached status unavailable',
                'message' => 'Use Refresh status to probe Gemini without slowing page load.',
                'last_error' => Cache::get(self::GEMINI_ERROR_CACHE_KEY),
                'checked_at' => null,
                'probe_model' => (string) config('workflow_enrichment.gemini_model', 'gemini-2.0-flash'),
            ];
        }

        $minutes = max(1, (int) config('workflow_enrichment.health_check_cache_minutes', 10));

        return Cache::remember(self::GEMINI_HEALTH_CACHE_KEY, now()->addMinutes($minutes), function () {
            return $this->probeGemini();
        });
    }

    public function recordGeminiError(string $message): void
    {
        Cache::put(self::GEMINI_ERROR_CACHE_KEY, $message, now()->addHours(24));
        Cache::forget(self::GEMINI_HEALTH_CACHE_KEY);
    }

    public function clearGeminiError(): void
    {
        Cache::forget(self::GEMINI_ERROR_CACHE_KEY);
        Cache::forget(self::GEMINI_HEALTH_CACHE_KEY);
    }

    public function isEnrichmentConfigured(): bool
    {
        return filled(config('gemini.api_key')) || filled(config('openrouter.api_key'));
    }

    public function configurationMessage(): ?string
    {
        if ($this->isEnrichmentConfigured()) {
            return null;
        }

        return 'AI enrichment requires GEMINI_API_KEY or OPENROUTER_API_KEY in the server environment.';
    }

    /**
     * @return array{state: string, label: string, balance: mixed, message: string|null}
     */
    protected function getOpenRouterHealth(bool $fetchBalance = true): array
    {
        if (! filled(config('openrouter.api_key'))) {
            return [
                'state' => 'not_configured',
                'label' => 'Not configured',
                'balance' => null,
                'message' => null,
            ];
        }

        $balance = $fetchBalance ? $this->getOpenRouterBalance() : Cache::get('workflow.openrouter_balance');

        return [
            'state' => $balance !== null ? 'ready' : 'unknown',
            'label' => $balance !== null ? 'Ready' : 'Balance unknown',
            'balance' => $balance,
            'message' => $balance !== null
                ? 'Remaining credits: '.$balance
                : 'Could not fetch OpenRouter balance.',
        ];
    }

    /**
     * @return array{state: string, label: string, message: string|null, last_error: string|null, checked_at: string, probe_model: string}
     */
    protected function probeGemini(): array
    {
        $apiKey = config('gemini.api_key');
        $model = (string) config('workflow_enrichment.gemini_model', 'gemini-2.0-flash');
        $checkedAt = now()->toIso8601String();
        $lastError = Cache::get(self::GEMINI_ERROR_CACHE_KEY);

        try {
            $request = Http::timeout(15)
                ->withHeaders([
                    'x-goog-api-key' => $apiKey,
                    'Content-Type' => 'application/json',
                ]);

            if (app()->isLocal() && config('app.allow_insecure_http_in_local', false)) {
                $request = $request->withoutVerifying();
            }

            $url = rtrim(config('gemini.base_url'), '/').'/models/'.$model.':generateContent';
            $response = $request->post($url, [
                'contents' => [
                    ['parts' => [['text' => 'Reply with OK']]],
                ],
                'generationConfig' => [
                    'maxOutputTokens' => 5,
                    'temperature' => 0,
                ],
            ]);

            if ($response->successful()) {
                $this->clearGeminiError();

                return [
                    'state' => 'ready',
                    'label' => 'Ready',
                    'message' => 'Gemini API accepted a test request. Check AI Studio billing for remaining prepay balance.',
                    'last_error' => null,
                    'checked_at' => $checkedAt,
                    'probe_model' => $model,
                ];
            }

            $message = $response->json('error.message') ?? mb_substr($response->body(), 0, 300);
            $this->recordGeminiError($message);

            $state = $this->classifyGeminiError($response->status(), $message);

            return [
                'state' => $state,
                'label' => match ($state) {
                    'depleted' => 'Credits depleted',
                    'invalid' => 'Invalid key',
                    default => 'Error',
                },
                'message' => $message,
                'last_error' => $message,
                'checked_at' => $checkedAt,
                'probe_model' => $model,
            ];
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            $this->recordGeminiError($message);

            return [
                'state' => 'error',
                'label' => 'Error',
                'message' => $message,
                'last_error' => $message,
                'checked_at' => $checkedAt,
                'probe_model' => $model,
            ];
        }
    }

    protected function classifyGeminiError(int $status, string $message): string
    {
        $lower = strtolower($message);

        if ($status === 429
            || str_contains($lower, 'prepayment credits are depleted')
            || str_contains($lower, 'resource_exhausted')
            || str_contains($lower, 'quota')) {
            return 'depleted';
        }

        if ($status === 403
            || str_contains($lower, 'api key')
            || str_contains($lower, 'permission denied')) {
            return 'invalid';
        }

        return 'error';
    }
}
