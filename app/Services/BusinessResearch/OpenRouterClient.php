<?php

namespace App\Services\BusinessResearch;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenRouterClient
{
    /**
     * @return array{content: string, model: string, tokens: int|null, raw: array}
     */
    public function chatWithWebSearch(string $systemPrompt, string $userPrompt, bool $enableWebSearch = true): array
    {
        return $this->request($systemPrompt, $userPrompt, $enableWebSearch);
    }

    /**
     * @return array{content: string, model: string, tokens: int|null, raw: array}
     */
    public function chat(string $systemPrompt, string $userPrompt, ?int $maxTokens = null): array
    {
        return $this->request($systemPrompt, $userPrompt, false, $maxTokens);
    }

    /**
     * Low-token pipeline fallback (lead import enrichment).
     *
     * @return array{content: string, model: string, tokens: int|null, raw: array}
     */
    public function chatForPipeline(string $systemPrompt, string $userPrompt): array
    {
        return $this->request(
            $systemPrompt,
            $userPrompt,
            (bool) config('workflow_enrichment.openrouter_web_search_enabled', false),
            (int) config('workflow_enrichment.openrouter_max_tokens', 2048),
        );
    }

    /**
     * Call-summary path: few fast models + short timeout (skip long paid/free cascades).
     *
     * @param  list<string>|null  $preferredModels
     * @return array{content: string, model: string, tokens: int|null, raw: array}
     */
    public function chatForCallSummary(
        string $systemPrompt,
        string $userPrompt,
        ?int $maxTokens = 220,
        ?array $preferredModels = null,
    ): array {
        $models = array_values(array_unique(array_filter($preferredModels ?? [
            config('openrouter.call_summary_model'),
            ...((array) config('openrouter.call_summary_fallback_models', [])),
        ])));

        if ($models === []) {
            $models = ['openai/gpt-oss-20b:free', 'meta-llama/llama-3.3-70b-instruct:free'];
        }

        // Keep the cascade short — long fallbacks make the popup feel stuck.
        $models = array_slice($models, 0, max(1, (int) config('openrouter.call_summary_max_models', 2)));
        $timeout = max(6, (int) config('openrouter.call_summary_timeout', 14));

        return $this->requestWithModels($systemPrompt, $userPrompt, false, $maxTokens, $models, $timeout);
    }

    /**
     * @return array{content: string, model: string, tokens: int|null, raw: array}
     */
    protected function request(string $systemPrompt, string $userPrompt, bool $enableWebSearch, ?int $maxTokens = null): array
    {
        $models = array_values(array_unique(array_filter([
            config('openrouter.model'),
            ...config('openrouter.fallback_models', []),
        ])));

        // Always try the free auto-router early — specific free models often exhaust daily caps first.
        if (! in_array('openrouter/free', $models, true)) {
            array_unshift($models, 'openrouter/free');
        } else {
            $models = array_values(array_unique(array_merge(
                ['openrouter/free'],
                array_values(array_filter($models, fn ($model) => $model !== 'openrouter/free'))
            )));
        }

        return $this->requestWithModels($systemPrompt, $userPrompt, $enableWebSearch, $maxTokens, $models);
    }

    /**
     * @param  list<string>  $models
     * @return array{content: string, model: string, tokens: int|null, raw: array}
     */
    protected function requestWithModels(
        string $systemPrompt,
        string $userPrompt,
        bool $enableWebSearch,
        ?int $maxTokens,
        array $models,
        ?int $timeoutSeconds = null,
    ): array {
        $apiKey = config('openrouter.api_key');

        if (! $apiKey) {
            throw new \RuntimeException('OPENROUTER_API_KEY is not configured in .env');
        }

        $lastError = null;
        $quotaError = null;
        $timeout = $timeoutSeconds ?? (int) config('openrouter.timeout', 120);

        foreach ($models as $model) {
            $payload = [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'temperature' => 0.3,
                'max_tokens' => $maxTokens ?? 4096,
            ];

            if ($enableWebSearch && config('openrouter.web_search_enabled', true)) {
                $payload['tools'] = [
                    ['type' => 'openrouter:web_search'],
                ];
            }

            $request = Http::connectTimeout(3)
                ->timeout($timeout)
                ->withHeaders([
                    'Authorization' => 'Bearer '.$apiKey,
                    'HTTP-Referer' => config('openrouter.site_url'),
                    'X-Title' => config('openrouter.site_name'),
                    'Content-Type' => 'application/json',
                ]);

            if (app()->isLocal() && config('app.allow_insecure_http_in_local', false)) {
                $request = $request->withoutVerifying();
            }

            try {
                $response = $request->post(config('openrouter.base_url').'/chat/completions', $payload);
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                Log::warning('OpenRouter request failed, trying fallback', [
                    'model' => $model,
                    'error' => $lastError,
                ]);
                continue;
            }

            if (in_array($response->status(), [402, 404, 429], true)) {
                $lastError = $response->json('error.message', $response->body());
                if (is_string($lastError) && (
                    str_contains(strtolower($lastError), 'free-models-per-day')
                    || str_contains(strtolower($lastError), 'add 10 credits')
                    || str_contains(strtolower($lastError), 'insufficient credits')
                )) {
                    $quotaError = $lastError;
                }
                Log::warning('OpenRouter model unavailable, trying fallback', [
                    'model' => $model,
                    'status' => $response->status(),
                    'error' => $lastError,
                ]);

                continue;
            }

            if (! $response->successful()) {
                $lastError = $response->json('error.message', $response->body());
                Log::warning('OpenRouter API error, trying fallback', [
                    'model' => $model,
                    'status' => $response->status(),
                    'error' => $lastError,
                ]);
                continue;
            }

            $data = $response->json();
            $content = $this->extractContent($data);

            return [
                'content' => $content,
                'model' => $data['model'] ?? $model,
                'tokens' => $data['usage']['total_tokens'] ?? null,
                'raw' => $data,
            ];
        }

        throw new \RuntimeException('All OpenRouter models failed. Last error: '.($quotaError ?? $lastError ?? 'unknown'));
    }

    protected function extractContent(array $data): string
    {
        $message = $data['choices'][0]['message'] ?? [];
        $content = $message['content'] ?? '';

        if (is_array($content)) {
            $parts = [];
            foreach ($content as $part) {
                if (is_array($part) && ($part['type'] ?? '') === 'text') {
                    $parts[] = $part['text'] ?? '';
                } elseif (is_string($part)) {
                    $parts[] = $part;
                }
            }

            $content = implode("\n", array_filter($parts));
        }

        if ($content === '' && ! empty($message['tool_calls'])) {
            // Some models return final answer only after tool calls in a follow-up turn
            $content = json_encode($message, JSON_PRETTY_PRINT);
        }

        return (string) $content;
    }
}
