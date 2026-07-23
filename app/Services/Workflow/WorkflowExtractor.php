<?php

namespace App\Services\Workflow;

use App\Models\WorkflowLead;
use App\Support\LeadDialablePhone;
use App\Services\BusinessResearch\BusinessResearchPrompt;
use App\Services\BusinessResearch\GeminiClient;
use App\Services\BusinessResearch\MarkdownReportParser;
use App\Services\BusinessResearch\OpenRouterClient;
use App\Services\BusinessResearch\ResearchInput;
use App\Services\BusinessResearch\ResearchResultSanitizer;
use App\Services\BusinessResearch\WebSearchService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

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
        if ($this->shouldUseSheetFallbackImmediately()) {
            Log::info("Using imported sheet enrichment for lead {$lead->id} (AI providers exhausted)");

            return $this->enrichFromImportedSheet($lead, 'AI providers exhausted; using imported sheet data.');
        }

        $input = ResearchInput::fromWorkflowLead($lead);
        $location = $this->resolveLocation($lead);

        $maxQueries = max(0, (int) config('workflow_enrichment.web_search_queries', 0));
        $webContext = [];

        if ($maxQueries > 0) {
            Log::info("Gathering web context for: {$input->businessName} in {$location} ({$maxQueries} queries)");
            $webContext = $this->webSearch->gatherContext(
                $input->businessName,
                $input->address ?: $location,
                $input->website,
                $maxQueries,
            );
        } else {
            Log::info("Skipping DuckDuckGo prefetch for: {$input->businessName} (Gemini Google Search only)");
        }

        $contextBlock = $this->webSearch->formatContextBlock($webContext);

        if ($maxQueries > 0 && count($webContext) === 0) {
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

            $attributes = $this->mapParsedToLeadAttributes($parsed, $report, $lead);

            return array_merge($attributes, [
                'status' => 'completed',
                'error_message' => null,
            ]);
        } catch (\Exception $e) {
            $this->providerStatus->recordGeminiError($e->getMessage());

            if ($this->isProviderQuotaExhausted($e->getMessage())
                && (bool) config('workflow_enrichment.sheet_fallback_enabled', true)
            ) {
                $this->markOpenRouterDailyExhausted($e->getMessage());
                Log::warning("AI enrichment unavailable for lead {$lead->id}; promoting imported sheet data", [
                    'error' => $e->getMessage(),
                ]);

                return $this->enrichFromImportedSheet($lead, $e->getMessage());
            }

            // Transient throttle — let the job retry instead of failing the lead.
            if ($this->isTransientProviderFailure($e->getMessage())) {
                throw $e;
            }

            Log::error("WorkflowExtractor failed for lead {$lead->id}: ".$e->getMessage());

            return [
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'researched_at' => now(),
            ];
        }
    }

    /**
     * Promote spreadsheet/import fields to an enriched lead when AI research is unavailable.
     *
     * @return array<string, mixed>
     */
    public function enrichFromImportedSheet(WorkflowLead $lead, ?string $reason = null): array
    {
        $raw = is_array($lead->raw_row) ? $lead->raw_row : [];
        $owner = $this->firstFilled([
            $lead->owner_name,
            $raw['Owner Name'] ?? null,
            $raw['owner_name'] ?? null,
            $raw['Owner'] ?? null,
            $raw['Contact Name'] ?? null,
            $raw['Contact'] ?? null,
        ]);
        $email = $this->firstFilled([
            $lead->direct_email,
            $lead->input_email,
            $raw['Email'] ?? null,
            $raw['email'] ?? null,
            $raw['Direct Email'] ?? null,
        ]);
        $phone = $this->firstFilled([
            $lead->direct_phone,
            $lead->input_phone,
            $lead->normalized_phone,
            $raw['Contact No.'] ?? null,
            $raw['Contact No'] ?? null,
            $raw['Phone'] ?? null,
            $raw['phone'] ?? null,
        ]);

        $note = $reason
            ? "Imported sheet enrichment (AI unavailable: {$reason})"
            : 'Imported sheet enrichment';

        $report = implode("\n", array_filter([
            '### Business Overview',
            '**Business Name:** '.($lead->business_name ?: 'Not Publicly Available'),
            '**Physical Address:** '.($lead->address ?: 'Not Publicly Available'),
            '**Website:** '.($lead->website ?: 'Not Publicly Available'),
            '',
            '### Direct Owner Contact',
            '**Owner Name:** '.($owner ?: 'Not Publicly Available'),
            '**Direct Phone:** '.($phone ?: 'Not Publicly Available'),
            '**Direct Email:** '.($email ?: 'Not Publicly Available'),
            '',
            '### Notes',
            $note,
        ]));

        $attributes = $this->mapParsedToLeadAttributes([
            'owner_name' => $owner,
            'direct_phone' => $phone,
            'direct_email' => $email,
            'physical_address' => $lead->address,
            'payment_processor' => null,
            'system_integration' => null,
            'primary_service' => null,
            'operating_hours' => null,
        ], [
            'content' => $report,
            'model' => 'sheet-import',
            'tokens' => 0,
        ], $lead);

        return array_merge($attributes, [
            'status' => 'completed',
            'error_message' => null,
        ]);
    }

    protected function shouldUseSheetFallbackImmediately(): bool
    {
        if (! (bool) config('workflow_enrichment.sheet_fallback_enabled', true)) {
            return false;
        }

        if (Cache::get('workflow-enrichment:openrouter-daily-exhausted')) {
            return true;
        }

        $geminiHealth = $this->providerStatus->getGeminiHealth();
        $geminiDepleted = ($geminiHealth['state'] ?? '') === 'depleted';

        return $geminiDepleted && Cache::get('workflow-enrichment:openrouter-daily-exhausted');
    }

    protected function markOpenRouterDailyExhausted(string $message): void
    {
        if (! $this->isProviderQuotaExhausted($message)) {
            return;
        }

        Cache::put('workflow-enrichment:openrouter-daily-exhausted', true, now()->addHours(6));
    }

    protected function isProviderQuotaExhausted(string $message): bool
    {
        $message = strtolower($message);

        foreach ([
            'credits depleted',
            'prepayment credits',
            'free-models-per-day',
            'insufficient credits',
            'add 10 credits',
            'quota exceeded',
            'billing',
        ] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    protected function isTransientProviderFailure(string $message): bool
    {
        $message = strtolower($message);

        foreach ([
            'rate limit',
            'temporarily throttled',
            'too many requests',
            'provider returned error',
            'timeout',
            'timed out',
            'http 429',
            'http 502',
            'http 503',
            'http 504',
        ] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<mixed>  $values
     */
    protected function firstFilled(array $values): ?string
    {
        foreach ($values as $value) {
            if (! is_scalar($value)) {
                continue;
            }
            $text = trim((string) $value);
            if ($text !== '' && $text !== '-' && strcasecmp($text, 'n/a') !== 0) {
                return $text;
            }
        }

        return null;
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
        $geminiState = (string) ($geminiHealth['state'] ?? '');
        $skipGemini = in_array($geminiState, ['depleted', 'invalid', 'error'], true)
            && filled(config('openrouter.api_key'));

        try {
            if ($skipGemini) {
                $reason = match ($geminiState) {
                    'depleted' => 'Gemini credits depleted',
                    'invalid' => 'Gemini API key invalid or project access denied',
                    default => 'Gemini unavailable',
                };
                throw new \RuntimeException($reason.' — using OpenRouter fallback.');
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

            // One OpenRouter call at a time + RPM cap. Free models thrash hard under parallel workers.
            $lock = Cache::lock('workflow-enrichment:openrouter-active', 120);
            if (! $lock->get()) {
                throw new \RuntimeException(
                    'OpenRouter fallback temporarily throttled; enrichment will retry.'
                );
            }

            try {
                if (! RateLimiter::attempt(
                    'workflow-enrichment:openrouter-fallback',
                    (int) config('workflow_enrichment.openrouter_fallback_rpm', 4),
                    fn () => true,
                    60,
                )) {
                    throw new \RuntimeException(
                        'OpenRouter fallback temporarily throttled; enrichment will retry.'
                    );
                }

                // Prefer the lean pipeline path (lower tokens, no tool-call loop).
                $result = method_exists($this->openRouter, 'chatForPipeline')
                    ? $this->openRouter->chatForPipeline($systemPrompt, $userPrompt)
                    : $this->openRouter->chat($systemPrompt, $userPrompt);

                if (! $this->isAcceptableResult($result['content'])) {
                    throw new \RuntimeException('OpenRouter fallback returned an incomplete enrichment report.');
                }

                Cache::forget('workflow-enrichment:openrouter-daily-exhausted');

                return [
                    'content' => $result['content'],
                    'model' => $result['model'],
                    'tokens' => $result['tokens'],
                ];
            } catch (\Exception $openRouterError) {
                $this->markOpenRouterDailyExhausted($openRouterError->getMessage());
                throw $openRouterError;
            } finally {
                optional($lock)->release();
            }
        }
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @param  array{content: string, model: string, tokens: int|null}  $report
     * @return array<string, mixed>
     */
    protected function mapParsedToLeadAttributes(array $parsed, array $report, WorkflowLead $lead): array
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

        if ($mapped['direct_phone'] === 'Not Publicly Available' && filled($lead->input_phone)) {
            $mapped['direct_phone'] = trim((string) $lead->input_phone);
        }

        if ($mapped['direct_email'] === 'Not Publicly Available' && filled($lead->input_email)) {
            $mapped['direct_email'] = trim((string) $lead->input_email);
        }

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

        // Persist a real dialable number when enrichment left a placeholder.
        $temp = $lead->replicate();
        $temp->forceFill([
            'markdown_report' => $report['content'],
            'direct_phone' => $updates['direct_phone'] ?? $lead->direct_phone,
            'input_phone' => $lead->input_phone,
            'normalized_phone' => $lead->normalized_phone,
            'raw_row' => $lead->raw_row,
        ]);
        $phoneUpdates = LeadDialablePhone::syncAttributes($temp);
        if ($phoneUpdates !== []) {
            $updates = array_merge($updates, $phoneUpdates);
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
