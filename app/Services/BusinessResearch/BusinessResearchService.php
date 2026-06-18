<?php

namespace App\Services\BusinessResearch;

use App\Models\BusinessResearch;
use App\Models\CrmLead;
use Illuminate\Support\Facades\Log;

class BusinessResearchService
{
    public function __construct(
        protected GeminiClient $gemini,
        protected OpenRouterClient $openRouter,
        protected WebSearchService $webSearch,
        protected MarkdownReportParser $markdownParser,
        protected ResearchResultSanitizer $sanitizer,
    ) {}

    public function research(BusinessResearch $research): void
    {
        $research->update(['status' => 'processing']);

        try {
            $input = ResearchInput::fromBusinessResearch($research);
            $output = $this->runResearch($input);
            $fillable = array_flip($research->getFillable());
            $attributes = $this->sanitizer->sanitize(
                array_intersect_key($output['attributes'], $fillable)
            );

            $research->update(array_merge($attributes, [
                'status' => 'completed',
                'completed_at' => now(),
            ]));
        } catch (\Throwable $e) {
            $research->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            throw $e;
        }
    }

    public function enrichLead(CrmLead $lead): void
    {
        $lead->update(['status' => 'processing']);
        $lead->campaign?->refreshCountsThrottled();

        try {
            $input = ResearchInput::fromCrmLead($lead);
            $output = $this->runResearch($input, crmLiteMode: true);

            $fillable = array_flip($lead->getFillable());
            $attributes = $this->sanitizer->sanitize(
                array_intersect_key($output['attributes'], $fillable)
            );

            $lead->update(array_merge($attributes, [
                'status' => 'completed',
                'researched_at' => now(),
            ]));
        } catch (\Throwable $e) {
            $lead->update([
                'status' => 'failed',
                'error_message' => mb_substr($e->getMessage(), 0, 2000),
                'researched_at' => now(),
            ]);

            throw $e;
        } finally {
            $lead->campaign?->refreshCountsThrottled();
        }
    }

    /**
     * @return array{attributes: array<string, mixed>, parsed: array<string, mixed>}
     */
    public function runResearch(ResearchInput $input, bool $crmLiteMode = false): array
    {
        $queries = $this->webSearch->buildSearchQueries(
            $input->businessName,
            $input->address,
            $input->website,
        );

        $webContextFirst = $crmLiteMode
            ? config('crm.web_context_first', false)
            : config('business_research.web_context_first', true);

        $maxDdgQueries = $crmLiteMode
            ? config('crm.max_ddg_queries', 6)
            : config('business_research.max_search_queries', 14);

        $webContext = $webContextFirst
            ? $this->webSearch->gatherContext(
                $input->businessName,
                $input->address,
                $input->website,
                $maxDdgQueries,
            )
            : [];

        $provider = config('business_research.provider', 'gemini');
        $systemPrompt = $crmLiteMode
            ? BusinessResearchPrompt::systemBulk()
            : BusinessResearchPrompt::system();
        $contextBlock = $this->webSearch->formatContextBlock($webContext);
        $researchPrompt = $crmLiteMode
            ? BusinessResearchPrompt::buildBulk($input, $contextBlock)
            : BusinessResearchPrompt::build($input, $contextBlock);

        $result = match ($provider) {
            'openrouter' => $this->researchViaOpenRouter($input, $systemPrompt, $researchPrompt, $webContext),
            default => $this->researchViaGemini(
                $input,
                $systemPrompt,
                $researchPrompt,
                $webContext,
                $contextBlock,
                $crmLiteMode,
            ),
        };

        $parsed = $this->markdownParser->parse($result['raw_response']);

        return [
            'parsed' => $parsed,
            'attributes' => [
                'owner_name' => $parsed['owner_name'] ?? null,
                'owner_title' => null,
                'co_owners' => null,
                'emails' => $parsed['emails'] ?? null,
                'phones' => $parsed['phones'] ?? null,
                'payment_processor' => $parsed['payment_processor'] ?? null,
                'pos_system' => $parsed['pos_system'] ?? null,
                'field_service_software' => $parsed['field_service_software'] ?? null,
                'business_type' => $parsed['business_type'] ?? null,
                'is_franchise' => $parsed['is_franchise'] ?? null,
                'franchise_brand' => $parsed['franchise_brand'] ?? null,
                'summary' => $parsed['summary'] ?? null,
                'structured_data' => $parsed,
                'sources' => $result['sources'],
                'search_queries' => array_merge(
                    $queries,
                    array_map(fn ($r) => 'ddg:'.$r['query'], array_slice($webContext, 0, 20)),
                ),
                'confidence' => $parsed['confidence'] ?? 'medium',
                'raw_response' => $result['raw_response'],
                'model_used' => $result['model_used'],
                'tokens_used' => $result['tokens_used'],
                // CRM-specific normalized columns
                'direct_phone' => $parsed['direct_phone'] ?? null,
                'direct_email' => $parsed['direct_email'] ?? null,
                'physical_address' => $parsed['physical_address'] ?? null,
                'primary_service' => $parsed['primary_service'] ?? null,
                'operating_hours' => $parsed['operating_hours'] ?? null,
            ],
        ];
    }

    /**
     * @param  array<int, array{title: string, url: string, snippet: string, query?: string, source?: string}>  $webContext
     * @return array{raw_response: string, model_used: string|null, tokens_used: int|null, sources: array}
     */
    protected function researchViaGemini(
        ResearchInput $input,
        string $systemPrompt,
        string $researchPrompt,
        array $webContext,
        string $contextBlock,
        bool $crmLiteMode = false,
    ): array {
        if (! config('gemini.api_key')) {
            throw new \RuntimeException('GEMINI_API_KEY is not configured in .env');
        }

        $geminiOptions = $crmLiteMode ? $this->crmGeminiOptions() : [];

        $tokensUsed = 0;
        $allSources = [];
        $geminiSearchQueries = [];
        $lastError = null;

        $followUpEnabled = $crmLiteMode
            ? config('crm.follow_up_enabled', false)
            : config('business_research.follow_up_enabled', true);

        try {
            $result = $this->gemini->researchWithGoogleSearch($systemPrompt, $researchPrompt, $geminiOptions);
            $tokensUsed += $result['tokens'] ?? 0;
            $allSources = array_merge($allSources, $result['sources']);
            $geminiSearchQueries = $result['search_queries'] ?? [];

            $parsed = $this->markdownParser->parse($result['content']);
            $rawResponse = $result['content'];
            $modelUsed = 'gemini:'.$result['model'];

            if ($followUpEnabled && $this->needsFollowUp($parsed, $crmLiteMode)) {
                Log::info('Running follow-up Gemini pass for missing fields', [
                    'business' => $input->businessName,
                    'gaps' => $this->missingFields($parsed),
                ]);

                $followUpPrompt = BusinessResearchPrompt::buildFollowUp($input, $parsed, $contextBlock);

                try {
                    $followUp = $this->gemini->researchWithGoogleSearch($systemPrompt, $followUpPrompt, $geminiOptions);
                    $tokensUsed += $followUp['tokens'] ?? 0;
                    $allSources = array_merge($allSources, $followUp['sources']);
                    $geminiSearchQueries = array_merge($geminiSearchQueries, $followUp['search_queries'] ?? []);

                    if ($this->isAcceptableResult($followUp['content'], $crmLiteMode)) {
                        $followUpParsed = $this->markdownParser->parse($followUp['content']);
                        if ($this->scoreParsed($followUpParsed) >= $this->scoreParsed($parsed)) {
                            $rawResponse = $followUp['content'];
                            $modelUsed = 'gemini:'.$followUp['model'].' (follow-up)';
                            $parsed = $followUpParsed;
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning('Follow-up Gemini pass failed', ['error' => $e->getMessage()]);
                }
            }

            if ($this->isAcceptableResult($rawResponse, $crmLiteMode)) {
                return [
                    'raw_response' => $rawResponse,
                    'model_used' => $modelUsed,
                    'tokens_used' => $tokensUsed ?: null,
                    'sources' => $this->mergeSources($allSources, $webContext, $geminiSearchQueries),
                ];
            }

            $lastError = $crmLiteMode
                ? 'No useful owner or contact data found'
                : 'Response missing required markdown schema';
        } catch (\Throwable $e) {
            $lastError = $e->getMessage();
            Log::warning('Primary Gemini pass failed', ['error' => $lastError]);
        }

        throw new \RuntimeException(
            'Gemini research failed'
            .($lastError ? ': '.$lastError : '. Check GEMINI_API_KEY and billing in Google AI Studio.')
        );
    }

    /**
     * @return array{
     *     models: array<int, string>,
     *     thinking_budget: int,
     *     timeout: int,
     *     max_output_tokens: int,
     * }
     */
    protected function crmGeminiOptions(): array
    {
        return [
            'models' => array_values(array_unique(array_filter([
                config('crm.gemini_model'),
                ...config('crm.gemini_fallback_models', []),
            ]))),
            'thinking_budget' => config('crm.gemini_thinking_budget', 0),
            'timeout' => config('crm.gemini_timeout', 90),
            'max_output_tokens' => config('crm.max_output_tokens', 8192),
        ];
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    protected function needsFollowUp(array $parsed, bool $crmLiteMode = false): bool
    {
        if ($crmLiteMode) {
            return empty($parsed['owner_name'])
                && empty($parsed['payment_processor'])
                && empty($parsed['direct_phone'])
                && empty($parsed['direct_email']);
        }

        return $this->scoreParsed($parsed) < 3;
    }

    protected function isAcceptableResult(string $rawResponse, bool $crmLiteMode): bool
    {
        if ($this->markdownParser->isValidReport($rawResponse)) {
            return true;
        }

        if (! $crmLiteMode) {
            return false;
        }

        return $this->markdownParser->hasMinimumUsefulData(
            $this->markdownParser->parse($rawResponse)
        );
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return array<int, string>
     */
    protected function missingFields(array $parsed): array
    {
        $missing = [];
        foreach (['owner_name', 'payment_processor', 'direct_phone', 'direct_email'] as $field) {
            if (empty($parsed[$field])) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    protected function scoreParsed(array $parsed): int
    {
        $score = 0;
        foreach (['owner_name', 'payment_processor', 'direct_phone', 'direct_email', 'physical_address', 'operating_hours'] as $field) {
            if (! empty($parsed[$field])) {
                $score++;
            }
        }

        return $score;
    }

    /**
     * @param  array<int, array{title: string, url: string, note: string|null}>  $geminiSources
     * @param  array<int, array{title: string, url: string, snippet: string, query?: string, source?: string}>  $webContext
     * @param  array<int, string>  $geminiSearchQueries
     * @return array<int, array{title: string, url: string, note: string|null}>
     */
    protected function mergeSources(array $geminiSources, array $webContext, array $geminiSearchQueries): array
    {
        $merged = [];
        $seen = [];

        foreach ($geminiSources as $source) {
            $url = $source['url'] ?? '';
            if ($url && ! isset($seen[$url])) {
                $seen[$url] = true;
                $merged[] = $source;
            }
        }

        foreach (array_slice($webContext, 0, 15) as $ctx) {
            $url = $ctx['url'] ?? '';
            if ($url && ! isset($seen[$url])) {
                $seen[$url] = true;
                $merged[] = [
                    'title' => ($ctx['source'] ?? 'web').': '.$ctx['title'],
                    'url' => $url,
                    'note' => mb_substr($ctx['snippet'], 0, 150),
                ];
            }
        }

        foreach ($geminiSearchQueries as $query) {
            $merged[] = [
                'title' => 'Google Search: '.$query,
                'url' => 'https://www.google.com/search?q='.urlencode($query),
                'note' => 'Gemini grounding query',
            ];
        }

        return array_slice($merged, 0, 30);
    }

    /**
     * @param  array<int, array{title: string, url: string, snippet: string}>  $webContext
     * @return array{raw_response: string, model_used: string|null, tokens_used: int|null, sources: array}
     */
    protected function researchViaOpenRouter(
        ResearchInput $input,
        string $systemPrompt,
        string $researchPrompt,
        array $webContext,
    ): array {
        if (! config('openrouter.api_key')) {
            throw new \RuntimeException('OPENROUTER_API_KEY is not configured in .env');
        }

        $result = $this->openRouter->chatWithWebSearch($systemPrompt, $researchPrompt, true);

        return [
            'raw_response' => $result['content'],
            'model_used' => $result['model'],
            'tokens_used' => $result['tokens'],
            'sources' => $this->mergeSources([], $webContext, []),
        ];
    }
}
