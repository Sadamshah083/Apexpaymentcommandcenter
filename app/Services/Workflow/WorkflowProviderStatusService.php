<?php

namespace App\Services\Workflow;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WorkflowProviderStatusService
{
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
        return config('gemini.api_key') ? 'Paid / Active' : 'Not Configured';
    }
}
