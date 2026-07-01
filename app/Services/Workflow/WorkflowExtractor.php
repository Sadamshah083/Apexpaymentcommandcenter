<?php

namespace App\Services\Workflow;

use App\Models\WorkflowLead;
use App\Services\BusinessResearch\BusinessResearchPrompt;
use App\Services\BusinessResearch\GeminiClient;
use App\Services\BusinessResearch\MarkdownReportParser;
use App\Services\BusinessResearch\OpenRouterClient;
use App\Services\BusinessResearch\ResearchInput;
use App\Services\BusinessResearch\ResearchResultSanitizer;
use App\Services\BusinessResearch\WebSearchService;
use Illuminate\Support\Facades\Log;

class WorkflowExtractor
{
    public function __construct(
        protected GeminiClient $gemini,
        protected WebSearchService $webSearch,
        protected WorkflowProviderStatusService $providerStatus,
        protected MarkdownReportParser $markdownParser,
        protected ResearchResultSanitizer $sanitizer,
        protected OpenRouterClient $openRouter,
    ) {}

    /**
     * Enrich lead with business intelligence.
     */
    public function extract(WorkflowLead $lead, ?string $customPrompt = null): array
    {
        $input = ResearchInput::fromWorkflowLead($lead);
        $location = $this->resolveLocation($lead);

        $maxQueries = max(1, (int) config('workflow_enrichment.web_search_queries', 8));
        Log::info("Gathering web context for: {$input->businessName} in {$location} ({$maxQueries} queries)");

        $webContext = $this->webSearch->gatherContext(
            $input->businessName,
            $input->address ?: $location,
            $input->website,
            $maxQueries,
        );
        $contextBlock = $this->webSearch->formatContextBlock($webContext);

        if (count($webContext) === 0) {
            Log::warning('Workflow enrichment has no DuckDuckGo context — relying on Gemini Google Search', [
                'lead_id' => $lead->id,
                'business' => $input->businessName,
            ]);
        }

        $systemPrompt = BusinessResearchPrompt::systemBulk();

        if ($customPrompt) {
            $userPrompt = str_replace(
                ['[INSERT BUSINESS NAME HERE]', '[INSERT CITY/STATE HERE]', '{{ business_name }}', '{{ location }}'],
                [$input->businessName, $location, $input->businessName, $location],
                $customPrompt
            );
            $userPrompt .= "\n\n### Web Search Results (SERP Context)\n".$contextBlock;
        } else {
            $userPrompt = BusinessResearchPrompt::buildBulk($input, $contextBlock);
        }

        try {
            $report = $this->runResearch($userPrompt, $systemPrompt, $webContext);
            $parsed = $this->markdownParser->parse($report['content']);

            if ($this->shouldRunFollowUp($parsed) && ! $customPrompt) {
                $followUpPrompt = BusinessResearchPrompt::buildFollowUp($input, $parsed, $contextBlock);
                try {
                    $followUp = $this->runResearch($followUpPrompt, $systemPrompt, $webContext);
                    $followUpParsed = $this->markdownParser->parse($followUp['content']);
                    if ($this->scoreParsed($followUpParsed) > $this->scoreParsed($parsed)) {
                        $report = $followUp;
                        $parsed = $followUpParsed;
                    }
                } catch (\Throwable $e) {
                    Log::warning('Workflow enrichment follow-up pass failed: '.$e->getMessage());
                }
            }

            $attributes = $this->mapParsedToLeadAttributes($parsed, $report);

            return array_merge($attributes, [
                'status' => 'completed',
                'error_message' => null,
            ]);
        } catch (\Exception $e) {
            $this->providerStatus->recordGeminiError($e->getMessage());
            Log::error("WorkflowExtractor failed for lead {$lead->id}: ".$e->getMessage());

            return [
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'researched_at' => now(),
            ];
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $webContext
     * @return array{content: string, model: string, tokens: int|null}
     */
    protected function runResearch(string $userPrompt, string $systemPrompt, array $webContext): array
    {
        $models = array_values(array_unique(array_filter(
            config('workflow_enrichment.gemini_model')
                ? [config('workflow_enrichment.gemini_model'), ...config('workflow_enrichment.gemini_fallback_models', [])]
                : [config('gemini.model'), 'gemini-2.5-flash', 'gemini-2.5-pro']
        )));

        $options = [
            'models' => $models,
            'google_search_enabled' => (bool) config('workflow_enrichment.gemini_google_search_enabled', true),
            'thinking_budget' => (int) config('workflow_enrichment.gemini_thinking_budget', 0),
            'max_output_tokens' => (int) config('workflow_enrichment.gemini_max_output_tokens', 4096),
            'timeout' => (int) config('workflow_enrichment.gemini_timeout', 120),
        ];

        $geminiHealth = $this->providerStatus->getGeminiHealth();
        $skipGemini = ($geminiHealth['state'] ?? '') === 'depleted'
            && filled(config('openrouter.api_key'));

        try {
            if ($skipGemini) {
                throw new \RuntimeException('Gemini credits depleted — using OpenRouter fallback.');
            }

            $lastGeminiContent = '';
            for ($attempt = 1; $attempt <= 2; $attempt++) {
                $result = $this->gemini->researchWithGoogleSearch($systemPrompt, $userPrompt, $options);
                $this->providerStatus->clearGeminiError();

                $content = $result['content'];
                if (! empty($result['sources'])) {
                    $content = $this->appendSourceNotes($content, $result['sources']);
                }
                $lastGeminiContent = $content;

                if ($this->isAcceptableResult($content)) {
                    return [
                        'content' => $content,
                        'model' => $result['model'],
                        'tokens' => $result['tokens'],
                    ];
                }

                Log::warning('Gemini enrichment report below acceptance threshold', [
                    'attempt' => $attempt,
                    'length' => mb_strlen($content),
                    'has_schema' => $this->markdownParser->hasReportSchema($content),
                ]);

                if ($attempt < 2) {
                    usleep(500_000);
                }
            }

            throw new \RuntimeException('Gemini returned an incomplete enrichment report.');
        } catch (\Exception $e) {
            $this->providerStatus->recordGeminiError($e->getMessage());
            Log::warning('Gemini failed in WorkflowExtractor. Falling back to OpenRouter: '.$e->getMessage());

            // DDG context is already in the prompt — plain chat avoids OpenRouter tool-call
            // responses that never return the markdown schema.
            $result = $this->openRouter->chat($systemPrompt, $userPrompt);

            if (! $this->isAcceptableResult($result['content'])) {
                throw new \RuntimeException('OpenRouter fallback returned an incomplete enrichment report.');
            }

            return [
                'content' => $result['content'],
                'model' => $result['model'],
                'tokens' => $result['tokens'],
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @param  array{content: string, model: string, tokens: int|null}  $report
     * @return array<string, mixed>
     */
    protected function mapParsedToLeadAttributes(array $parsed, array $report): array
    {
        $mapped = $this->sanitizer->sanitize([
            'owner_name' => $this->displayValue($parsed['owner_name'] ?? null),
            'direct_phone' => $this->displayValue($parsed['direct_phone'] ?? null),
            'direct_email' => $this->displayValue($parsed['direct_email'] ?? null),
            'payment_processor' => $this->displayValue($parsed['payment_processor'] ?? null),
            'system_integration' => $this->displayValue($parsed['system_integration'] ?? null),
            'primary_service' => $this->displayValue($parsed['primary_service'] ?? null),
            'operating_hours' => $this->displayValue($parsed['operating_hours'] ?? null),
        ]);

        $updates = [
            'markdown_report' => $report['content'],
            'model_used' => $report['model'],
            'tokens_used' => $report['tokens'],
            'researched_at' => now(),
            ...$mapped,
        ];

        if (filled($parsed['physical_address'] ?? null)) {
            $updates['address'] = $parsed['physical_address'];
        }

        return $updates;
    }

    protected function displayValue(?string $value): string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' ? $value : 'Not Publicly Available';
    }

    protected function resolveLocation(WorkflowLead $lead): string
    {
        $location = trim(implode(', ', array_filter([$lead->city, $lead->state])));
        if ($location !== '') {
            return $location;
        }

        return $lead->address ?: 'United States';
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    protected function shouldRunFollowUp(array $parsed): bool
    {
        if (! config('workflow_enrichment.follow_up_enabled', true)) {
            return false;
        }

        return $this->scoreParsed($parsed) < (int) config('workflow_enrichment.follow_up_min_score', 3);
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    protected function scoreParsed(array $parsed): int
    {
        $score = 0;
        foreach (['owner_name', 'payment_processor', 'direct_phone', 'direct_email', 'physical_address'] as $field) {
            if (! empty($parsed[$field])) {
                $score++;
            }
        }

        return $score;
    }

    protected function isAcceptableResult(string $content): bool
    {
        if ($this->markdownParser->isValidReport($content)) {
            return true;
        }

        if ($this->markdownParser->hasMinimumUsefulData(
            $this->markdownParser->parse($content)
        )) {
            return true;
        }

        // Gemini often returns the full schema with every field set to "Not Publicly Available"
        // for hard-to-research leads (food trucks, etc.) — still save the report.
        if ($this->markdownParser->hasReportSchema($content)) {
            return true;
        }

        // GRACEFUL FALLBACK: If the response is sufficiently long (e.g. > 150 chars),
        // we accept it even if it lacks strict markdown headers. This prevents the pipeline from stalling.
        return strlen(trim($content)) > 150;
    }

    /**
     * @param  array<int, array{title: string, url: string, note: string|null}>  $sources
     */
    protected function appendSourceNotes(string $content, array $sources): string
    {
        if ($sources === []) {
            return $content;
        }

        $lines = ["\n\n--- Gemini grounding sources ---"];
        foreach (array_slice($sources, 0, 10) as $source) {
            $lines[] = '- '.($source['title'] ?? 'Source').': '.($source['url'] ?? '');
        }

        return $content.implode("\n", $lines);
    }
}
