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
    public function chat(string $systemPrompt, string $userPrompt): array
    {
        return $this->request($systemPrompt, $userPrompt, false);
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
     * @return array{content: string, model: string, tokens: int|null, raw: array}
     */
    protected function request(string $systemPrompt, string $userPrompt, bool $enableWebSearch, ?int $maxTokens = null): array
    {
        $apiKey = config('openrouter.api_key');

        if (! $apiKey) {
            throw new \RuntimeException('OPENROUTER_API_KEY is not configured in .env');
        }

        $models = array_values(array_unique(array_filter([
            config('openrouter.model'),
            ...config('openrouter.fallback_models', []),
        ])));

        $lastError = null;

        foreach ($models as $model) {
            $payload = [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'temperature' => 0.2,
                'max_tokens' => $maxTokens ?? 4096,
            ];

            if ($enableWebSearch && config('openrouter.web_search_enabled', true)) {
                $payload['tools'] = [
                    ['type' => 'openrouter:web_search'],
                ];
            }

            $request = Http::timeout(config('openrouter.timeout', 120))
                ->withHeaders([
                    'Authorization' => 'Bearer '.$apiKey,
                    'HTTP-Referer' => config('openrouter.site_url'),
                    'X-Title' => config('openrouter.site_name'),
                    'Content-Type' => 'application/json',
                ]);

            if (app()->isLocal() && config('app.allow_insecure_http_in_local', false)) {
                $request = $request->withoutVerifying();
            }

            $response = $request->post(config('openrouter.base_url').'/chat/completions', $payload);

            if ($response->status() === 404 || $response->status() === 429) {
                $lastError = $response->json('error.message', $response->body());
                Log::warning('OpenRouter model unavailable, trying fallback', [
                    'model' => $model,
                    'status' => $response->status(),
                    'error' => $lastError,
                ]);

                continue;
            }

            if (! $response->successful()) {
                Log::error('OpenRouter API error', [
                    'model' => $model,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                throw new \RuntimeException('OpenRouter API error: '.$response->status().' — '.$response->json('error.message', $response->body()));
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

        throw new \RuntimeException('All OpenRouter models failed. Last error: '.($lastError ?? 'unknown'));
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
