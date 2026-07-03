<?php

namespace Tests\Unit\Services;

use App\Services\Workflow\WorkflowProviderStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WorkflowProviderStatusServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_gemini_probe_marks_depleted_on_429(): void
    {
        Cache::flush();
        config([
            'gemini.api_key' => 'test-key',
            'gemini.base_url' => 'https://generativelanguage.googleapis.com/v1beta',
            'workflow_enrichment.gemini_model' => 'gemini-2.0-flash',
            'workflow_enrichment.health_check_cache_minutes' => 10,
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'error' => [
                    'message' => 'Your prepayment credits are depleted.',
                    'status' => 'RESOURCE_EXHAUSTED',
                ],
            ], 429),
        ]);

        $service = new WorkflowProviderStatusService;
        $health = $service->getGeminiHealth();

        $this->assertSame('depleted', $health['state']);
        $this->assertSame('Credits depleted', $health['label']);
    }

    public function test_gemini_probe_marks_ready_on_success(): void
    {
        Cache::flush();
        config([
            'gemini.api_key' => 'test-key',
            'gemini.base_url' => 'https://generativelanguage.googleapis.com/v1beta',
            'workflow_enrichment.gemini_model' => 'gemini-2.0-flash',
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    ['content' => ['parts' => [['text' => 'OK']]]],
                ],
            ], 200),
        ]);

        $service = new WorkflowProviderStatusService;
        $health = $service->getGeminiHealth();

        $this->assertSame('ready', $health['state']);
    }

    public function test_gemini_health_can_skip_probe_when_cache_missing(): void
    {
        Cache::flush();
        config([
            'gemini.api_key' => 'test-key',
            'workflow_enrichment.gemini_model' => 'gemini-2.0-flash',
        ]);

        Http::fake();

        $service = new WorkflowProviderStatusService;
        $health = $service->getGeminiHealth(false);

        $this->assertSame('unknown', $health['state']);
        $this->assertSame('Not checked', $health['label']);
        $this->assertNull($health['checked_at']);
        Http::assertNothingSent();
    }
}
