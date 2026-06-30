<?php

namespace App\Services\Workflow;

use App\Models\WorkflowLead;
use App\Services\BusinessResearch\GeminiClient;
use Illuminate\Support\Facades\Log;

class WorkflowExtractor
{
    protected GeminiClient $gemini;
    protected \App\Services\BusinessResearch\WebSearchService $webSearch;

    public function __construct(GeminiClient $gemini, \App\Services\BusinessResearch\WebSearchService $webSearch)
    {
        $this->gemini = $gemini;
        $this->webSearch = $webSearch;
    }

    /**
     * Enrich lead with business intelligence
     */
    public function extract(WorkflowLead $lead, ?string $customPrompt = null): array
    {
        $businessName = $lead->business_name;
        $location = trim(($lead->city ? $lead->city . ', ' : '') . $lead->state);
        if (empty($location)) {
            $location = $lead->address ?: 'United States';
        }

        // Gather SERP context from DuckDuckGo/Web (Perplexity style)
        Log::info("Gathering SERP results for: {$businessName} in {$location}");
        $webContext = $this->webSearch->gatherContext(
            $businessName,
            $lead->address ?: $location,
            $lead->website,
            2 // Fetch 2 targeted queries for rapid pipeline throughput
        );
        $contextBlock = $this->webSearch->formatContextBlock($webContext);

        $systemPrompt = "You are a specialized business intelligence data extraction bot. Your sole task is to extract complete, accurate information for the business name provided, synthesizing details from the provided search engine results (SERP) and business web pages.";

        if ($customPrompt) {
            $userPrompt = str_replace(
                ['[INSERT BUSINESS NAME HERE]', '[INSERT CITY/STATE HERE]', '{{ business_name }}', '{{ location }}'],
                [$businessName, $location, $businessName, $location],
                $customPrompt
            );
            $userPrompt .= "\n\n### Web Search Results (SERP Context)\n" . $contextBlock;
        } else {
            $userPrompt = "Target Business: {$businessName}\n" .
                "Target Location (City/State): {$location}\n\n" .
                "### Web Search Results (SERP Context)\n" .
                $contextBlock . "\n\n" .
                "Search for and extract the following data points from the SERP context. You must present the final output using the exact Markdown schema provided below.\n\n" .
                "### Business Identity & Location\n" .
                "* **Business Name**: [Extract the official trade name or LLC name. If applicable, provide the hyperlink to their main web domain]\n" .
                "* **Physical Address**: [Extract the complete street address, suite number, city, state, and zip code]\n" .
                "* **Primary Service**: [List the core services provided, e.g., Master Barbering, Custom Bridal Tailoring, etc.]\n" .
                "* **Operating Hours**: [List the operational hours for Monday through Sunday]\n\n" .
                "### Owner & Contact Information\n" .
                "* **Direct Owner Name**: [Extract the exact first and last name of the business owner, founder, or managing member. If it is a corporate chain, list the current CEO]\n" .
                "* **Direct Phone Number**: [Extract the primary operational phone number]\n" .
                "* **Direct Email Address**: [Extract the public corporate email address. If they do not use an email and route through a specific portal like Facebook or Booksy, explicitly state that and provide the link]\n\n" .
                "### Payment Processor & Booking Software\n" .
                "* **Payment Processor**: [Identify the backend payment gateway or processing merchant network being used, e.g., Square, Stripe, Clover, Booksy Card Processing, Toast, etc.]\n" .
                "* **System Integration**: [Provide a brief, 2-sentence breakdown explaining how their point-of-sale (POS) hardware, booking software, or online invoicing system integrates with that specific payment processor]\n\n" .
                "STRICT COMPLIANCE RULES:\n" .
                "1. Do not use generic filler information. If a data point cannot be verified through web searches, output \"Not Publicly Available\".\n" .
                "2. Ensure you look up the specific location provided to avoid confusing identical business names in different states.\n" .
                "3. Keep the output clean, highly dense, and completely factual. Do not include introductory or concluding conversational text.";
        }

        try {
            $options = [
                'models' => [config('gemini.model'), 'gemini-2.5-pro', 'gemini-2.5-flash'],
                // Fall back to disabled search grounding if key is free tier, since DDG handles it
                'google_search_enabled' => false, 
                'thinking_budget' => 0, // Disabled thinking budget for fast extraction
                'timeout' => 240
            ];

            try {
                $result = $this->gemini->researchWithGoogleSearch($systemPrompt, $userPrompt, $options);
            } catch (\Exception $e) {
                Log::warning("Gemini failed in WorkflowExtractor. Falling back to OpenRouter: " . $e->getMessage());
                $openRouterClient = app(\App\Services\BusinessResearch\OpenRouterClient::class);
                $result = $openRouterClient->chat($systemPrompt, $userPrompt);
            }
            $report = $result['content'];

            // Parse fields
            $parsed = $this->parseReport($report);

            return [
                'status' => 'completed',
                'markdown_report' => $report,
                'owner_name' => $parsed['owner_name'],
                'direct_phone' => $parsed['direct_phone'],
                'direct_email' => $parsed['direct_email'],
                'payment_processor' => $parsed['payment_processor'],
                'system_integration' => $parsed['system_integration'],
                'primary_service' => $parsed['primary_service'],
                'operating_hours' => $parsed['operating_hours'],
                'model_used' => $result['model'],
                'tokens_used' => $result['tokens'],
                'researched_at' => now(),
                'error_message' => null
            ];
        } catch (\Exception $e) {
            Log::error("WorkflowExtractor failed for lead {$lead->id}: " . $e->getMessage());
            return [
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'researched_at' => now()
            ];
        }
    }

    /**
     * Parse fields from the markdown response
     */
    protected function parseReport(string $report): array
    {
        $fields = [
            'owner_name' => 'Direct Owner Name',
            'direct_phone' => 'Direct Phone Number',
            'direct_email' => 'Direct Email Address',
            'payment_processor' => 'Payment Processor',
            'system_integration' => 'System Integration',
            'primary_service' => 'Primary Service',
            'operating_hours' => 'Operating Hours'
        ];

        $results = [];

        foreach ($fields as $key => $label) {
            // Find "* **Label**: value"
            $pattern = '/\*\s*\*\*' . preg_quote($label, '/') . '\*\*\s*:\s*(.*)/i';
            if (preg_match($pattern, $report, $matches)) {
                $val = trim($matches[1]);
                // Remove bracketed placeholders or clean up md links
                $val = preg_replace('/^\[|\]$/', '', $val);
                $results[$key] = $val;
            } else {
                $results[$key] = 'Not Publicly Available';
            }
        }

        return $results;
    }
}
