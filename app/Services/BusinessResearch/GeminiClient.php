<?php

namespace App\Services\BusinessResearch;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiClient
{
    /**
     * @param  array{
     *     models?: array<int, string>,
     *     thinking_budget?: int,
     *     timeout?: int,
     *     max_output_tokens?: int,
     *     google_search_enabled?: bool,
     * }  $options
     * @return array{content: string, model: string, tokens: int|null, sources: array, search_queries: array, raw: array}
     */
    public function researchWithGoogleSearch(string $systemPrompt, string $userPrompt, array $options = []): array
    {
        $apiKey = config('gemini.api_key');

        if (! $apiKey) {
            throw new \RuntimeException('GEMINI_API_KEY is not configured in .env');
        }

        $models = array_values(array_unique(array_filter(
            $options['models'] ?? [
                config('gemini.model'),
                ...config('gemini.fallback_models', []),
            ]
        )));

        $thinkingBudget = $options['thinking_budget'] ?? config('gemini.thinking_budget', 0);
        $timeout = $options['timeout'] ?? config('gemini.timeout', 240);
        $maxOutputTokens = $options['max_output_tokens'] ?? 16384;
        $googleSearchEnabled = $options['google_search_enabled'] ?? config('gemini.google_search_enabled', true);

        $lastError = null;

        foreach ($models as $model) {
            $payload = [
                'systemInstruction' => [
                    'parts' => [['text' => $systemPrompt]],
                ],
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [['text' => $userPrompt]],
                    ],
                ],
                'generationConfig' => [
                    'temperature' => 0.1,
                    'maxOutputTokens' => $maxOutputTokens,
                ],
            ];

            if ($this->supportsThinking($model) && $thinkingBudget > 0) {
                $payload['generationConfig']['thinkingConfig'] = [
                    'thinkingBudget' => $thinkingBudget,
                ];
            }

            if ($googleSearchEnabled) {
                $payload['tools'] = [
                    ['google_search' => new \stdClass],
                ];
            }

            $request = Http::timeout($timeout)
                ->withHeaders([
                    'x-goog-api-key' => $apiKey,
                    'Content-Type' => 'application/json',
                ]);

            if (app()->isLocal() && config('app.allow_insecure_http_in_local', false)) {
                $request = $request->withoutVerifying();
            }

            $url = rtrim(config('gemini.base_url'), '/').'/models/'.$model.':generateContent';

            $response = $request->post($url, $payload);

            if ($response->status() === 404 || $response->status() === 429) {
                $lastError = $response->json('error.message', $response->body());
                Log::warning('Gemini model unavailable, trying fallback', [
                    'model' => $model,
                    'status' => $response->status(),
                    'error' => $lastError,
                ]);

                continue;
            }

            if (! $response->successful()) {
                $message = $this->formatApiError($response);

                Log::error('Gemini API error', [
                    'model' => $model,
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 500),
                ]);

                throw new \RuntimeException('Gemini API error: '.$response->status().' — '.$message);
            }

            $data = $response->json();
            $content = $this->extractText($data);
            $sources = $this->extractGroundingSources($data);
            $searchQueries = $this->extractSearchQueries($data);

            if ($content === '') {
                $lastError = 'Model returned empty response';
                Log::warning('Gemini empty response, trying fallback model', ['model' => $model]);

                continue;
            }

            return [
                'content' => $content,
                'model' => $model,
                'tokens' => $data['usageMetadata']['totalTokenCount'] ?? null,
                'sources' => $sources,
                'search_queries' => $searchQueries,
                'raw' => $data,
            ];
        }

        throw new \RuntimeException('All Gemini models failed. Last error: '.($lastError ?? 'unknown'));
    }

    protected function supportsThinking(string $model): bool
    {
        return str_contains($model, '2.5-pro');
    }

    protected function extractText(array $data): string
    {
        $parts = $data['candidates'][0]['content']['parts'] ?? [];

        $texts = [];
        foreach ($parts as $part) {
            if (! empty($part['text']) && empty($part['thought'])) {
                $texts[] = $part['text'];
            }
        }

        return trim(implode("\n", $texts));
    }

    /**
     * @return array<int, array{title: string, url: string, note: string|null}>
     */
    protected function extractGroundingSources(array $data): array
    {
        $metadata = $data['candidates'][0]['groundingMetadata'] ?? [];
        $chunks = $metadata['groundingChunks'] ?? [];
        $sources = [];
        $seen = [];

        foreach ($chunks as $chunk) {
            $web = $chunk['web'] ?? [];
            $uri = $web['uri'] ?? $web['url'] ?? null;

            if (! $uri || isset($seen[$uri])) {
                continue;
            }

            $seen[$uri] = true;
            $sources[] = [
                'title' => $web['title'] ?? parse_url($uri, PHP_URL_HOST) ?? 'Web source',
                'url' => $uri,
                'note' => null,
            ];
        }

        return $sources;
    }

    /**
     * @return array<int, string>
     */
    protected function extractSearchQueries(array $data): array
    {
        $metadata = $data['candidates'][0]['groundingMetadata'] ?? [];
        $queries = $metadata['webSearchQueries'] ?? [];

        return array_values(array_filter($queries));
    }

    protected function formatApiError(\Illuminate\Http\Client\Response $response): string
    {
        $message = $response->json('error.message');
        $status = $response->json('error.status');
        $reason = $response->json('error.details.0.reason');

        if ($reason === 'API_KEY_SERVICE_BLOCKED' || str_contains((string) $message, 'API key')) {
            return ($message ?: 'Invalid API key')
                .'. Verify GEMINI_API_KEY from Google AI Studio, enable billing, and enable Generative Language API.';
        }

        if ($status === 'RESOURCE_EXHAUSTED' || $response->status() === 429) {
            return ($message ?: 'Quota exceeded').'. Enable billing for Google Search grounding on gemini-2.5-pro.';
        }

        return $message ?: mb_substr($response->body(), 0, 300);
    }
}
