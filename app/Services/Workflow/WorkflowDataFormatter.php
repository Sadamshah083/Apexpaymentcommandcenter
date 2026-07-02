<?php

namespace App\Services\Workflow;

use App\Services\BusinessResearch\GeminiClient;
use Illuminate\Support\Facades\Log;

class WorkflowDataFormatter
{
    protected GeminiClient $gemini;

    public function __construct(GeminiClient $gemini)
    {
        $this->gemini = $gemini;
    }

    /**
     * AI format a raw row into the universal system format
     */
    public function formatRow(array $rawRow, array $mapping): array
    {
        $batchResult = $this->formatRowsBatch([$rawRow], $mapping);
        return $batchResult[0] ?? $this->manualFallbackRow($rawRow, $mapping);
    }

    /**
     * AI format a batch of raw rows into the universal system format
     */
    public function formatRowsBatch(array $rawRows, array $mapping): array
    {
        if (empty($rawRows)) {
            return [];
        }

        if (config('workflow.use_manual_import_mapping', true)) {
            $results = [];
            foreach ($rawRows as $index => $rawRow) {
                $results[$index] = $this->manualFallbackRow($rawRow, $mapping);
            }

            return $results;
        }

        $systemPrompt = "You are an AI data formatting expert. Your task is to understand a list of raw spreadsheet rows and format them into our universal system structure.\n\n" .
            "Target fields for each row object:\n" .
            "- business_name (Clean company or business name)\n" .
            "- address (Street address line 1, e.g. 123 Main St, Suite A)\n" .
            "- city (City name)\n" .
            "- state (State name or abbreviation, e.g. AL, California)\n" .
            "- zip_code (ZIP / Postal code)\n" .
            "- country (Country name, e.g. United States)\n" .
            "- website (Cleaned website URL or root domain)\n" .
            "- input_phone (Cleaned phone number)\n" .
            "- input_email (Normalized email address)\n" .
            "- owner_name (Owner or primary contact name)\n\n" .
            "Instructions:\n" .
            "1. Extract values from the raw rows based on the suggested column mappings provided.\n" .
            "2. Clean and format the extracted values. If a full address is combined in a single field, split it into address, city, state, and zip_code correctly.\n" .
            "3. Clean up website domains (remove protocols) and normalize email addresses.\n" .
            "4. Respond with a raw JSON array containing formatted objects in the exact same order as the input rows. Output ONLY the raw JSON array (enclosed in []). Do not output markdown code blocks or wrapping text.";

        $userPrompt = "Column Mapping Configuration: " . json_encode($mapping) . "\n\n" .
            "Raw Rows Batch Data:\n" . json_encode($rawRows);

        try {
            $options = [
                'models' => [config('gemini.fallback_models.0', 'gemini-2.5-flash'), 'gemini-2.0-flash', 'gemini-2.5-pro'],
                'google_search_enabled' => false,
                'thinking_budget' => 0
            ];

            $result = $this->gemini->researchWithGoogleSearch($systemPrompt, $userPrompt, $options);
            $content = $result['content'];

            // Extract JSON block
            $firstBracket = strpos($content, '[');
            $lastBracket = strrpos($content, ']');
            if ($firstBracket !== false && $lastBracket !== false) {
                $jsonStr = substr($content, $firstBracket, $lastBracket - $firstBracket + 1);
                $formattedBatch = json_decode(trim($jsonStr), true);
            } else {
                $content = preg_replace('/```json\s*|```\s*/i', '', $content);
                $formattedBatch = json_decode(trim($content), true);
            }

            if (is_array($formattedBatch)) {
                $results = [];
                foreach ($rawRows as $index => $rawRow) {
                    $formatted = $formattedBatch[$index] ?? null;
                    if (!is_array($formatted)) {
                        $formatted = $this->manualFallbackRow($rawRow, $mapping);
                    } else {
                        foreach (['business_name', 'address', 'city', 'state', 'zip_code', 'country', 'website', 'input_phone', 'input_email', 'owner_name'] as $key) {
                            if (!isset($formatted[$key])) {
                                $formatted[$key] = null;
                            }
                        }
                    }
                    $results[$index] = $formatted;
                }
                return $results;
            }
        } catch (\Exception $e) {
            Log::error("WorkflowDataFormatter batch failed: " . $e->getMessage());
        }

        // Fallback to manual parsing mapping for each row in the batch
        $results = [];
        foreach ($rawRows as $index => $rawRow) {
            $results[$index] = $this->manualFallbackRow($rawRow, $mapping);
        }
        return $results;
    }

    protected function manualFallbackRow(array $rawRow, array $mapping): array
    {
        $fallback = [];
        foreach ($mapping as $key => $header) {
            $fallback[$key] = $header ? ($rawRow[$header] ?? null) : null;
        }
        return $fallback;
    }
}
