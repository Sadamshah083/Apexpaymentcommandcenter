<?php

namespace App\Services\Workflow;

use App\Services\BusinessResearch\GeminiClient;
use App\Support\SpreadsheetHeaderDetector;
use App\Support\SpreadsheetText;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Reader\Csv as CsvReader;

class WorkflowAiMapper
{
    protected GeminiClient $gemini;

    public function __construct(GeminiClient $gemini)
    {
        $this->gemini = $gemini;
    }

    /**
     * Get list of sheets and preview data
     */
    public function getFileSheets(string $filePath): array
    {
        $spreadsheetInfo = IOFactory::createReaderForFile($filePath);
        return $spreadsheetInfo->listWorksheetNames($filePath);
    }

    /**
     * @return array<int, mixed>
     */
    public function getHeaderRow(string $filePath, ?string $sheetName = null): array
    {
        return $this->detectHeaderRow($filePath, $sheetName)['headers'];
    }

    /**
     * @return array{index: int, headers: array<int, string>}
     */
    public function detectHeaderRow(string $filePath, ?string $sheetName = null): array
    {
        try {
            $reader = IOFactory::createReaderForFile($filePath);
            $reader->setReadDataOnly(true);
            $this->configureCsvReader($reader, $filePath);

            if ($sheetName && method_exists($reader, 'setLoadSheetsOnly')) {
                $reader->setLoadSheetsOnly([$sheetName]);
            }

            $spreadsheet = $reader->load($filePath);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = [];

            foreach ($sheet->getRowIterator(1, 25) as $row) {
                $cellIterator = $row->getCellIterator();
                $cellIterator->setIterateOnlyExistingCells(false);
                $rowData = [];
                foreach ($cellIterator as $cell) {
                    $rowData[] = $cell->getValue();
                }
                $rows[] = $rowData;
            }

            return SpreadsheetHeaderDetector::detect($rows);
        } catch (\Throwable $e) {
            Log::debug('Workflow header row extraction failed', [
                'file_path' => $filePath,
                'sheet' => $sheetName,
                'error' => $e->getMessage(),
            ]);

            return ['index' => 0, 'headers' => []];
        }
    }

    protected function configureCsvReader(mixed $reader, string $filePath): void
    {
        if ($reader instanceof CsvReader) {
            $reader->setInputEncoding('UTF-8');
        }
    }

    /**
     * Fast header mapping without calling Gemini (used on upload for instant response).
     *
     * @return array{headers: array<int, mixed>, mapping: array<string, string|null>}
     */
    public function fastMap(string $filePath, ?string $sheetName = null): array
    {
        $headers = $this->getHeaderRow($filePath, $sheetName);

        return [
            'headers' => $headers,
            'mapping' => $this->heuristicMap($headers),
        ];
    }

    /**
     * Map headers to universal format using Gemini, with heuristic fallback.
     */
    public function autoMap(string $filePath, ?string $sheetName = null): array
    {
        $headers = $this->getHeaderRow($filePath, $sheetName);

        if (empty($headers)) {
            return [
                'headers' => [],
                'mapping' => $this->emptyMapping(),
            ];
        }

        $aiMapping = $this->requestAiMapping($filePath, $sheetName, $headers);
        $mapping = $this->mergeMappings(
            $this->heuristicMap($headers),
            $aiMapping,
            $headers
        );

        return [
            'headers' => $headers,
            'mapping' => $mapping,
        ];
    }

    /**
     * @return array<string, string|null>
     */
    public function heuristicMap(array $headers): array
    {
        $mapping = $this->emptyMapping();
        $candidates = $this->normalizeHeaders($headers);

        if ($candidates === []) {
            return $mapping;
        }

        $fieldPatterns = [
            'business_name' => [
                'business name' => 100,
                'company name' => 100,
                'business' => 95,
                'company' => 90,
                'merchant' => 85,
                'store name' => 85,
                'shop name' => 85,
                'account name' => 80,
                'client name' => 80,
                'trade name' => 80,
                'dba' => 75,
                'name' => 45,
            ],
            'address' => [
                'street address' => 100,
                'address line 1' => 95,
                'address' => 90,
                'street' => 85,
                'addr' => 70,
            ],
            'city' => ['city' => 100, 'town' => 80],
            'state' => ['state' => 100, 'province' => 90, 'region' => 70],
            'zip_code' => ['zip code' => 100, 'postal code' => 100, 'zip' => 90, 'postcode' => 90],
            'country' => ['country' => 100],
            'website' => ['website' => 100, 'url' => 90, 'domain' => 85, 'web' => 70],
            'input_phone' => [
                'contact number' => 100,
                'phone number' => 100,
                'phone_number' => 100,
                'contact phone' => 95,
                'telephone' => 90,
                'mobile' => 90,
                'cell' => 85,
                'phone' => 100,
                'tel' => 70,
            ],
            'input_email' => ['email' => 100, 'e-mail' => 100, 'mail' => 70],
            'owner_name' => [
                'owner name' => 100,
                'owner' => 95,
                'contact name' => 95,
                'primary contact' => 90,
                'proprietor' => 85,
                'manager' => 70,
            ],
        ];

        foreach ($fieldPatterns as $field => $patterns) {
            $bestHeader = null;
            $bestScore = 0;

            foreach ($candidates as $candidate) {
                if ($field === 'business_name' && $this->isExcludedBusinessNameHeader($candidate['normalized'])) {
                    continue;
                }

                foreach ($patterns as $pattern => $score) {
                    $matchedScore = $this->scoreHeaderMatch($candidate['normalized'], $pattern, $score);
                    if ($matchedScore > $bestScore) {
                        $bestScore = $matchedScore;
                        $bestHeader = $candidate['original'];
                    }
                }
            }

            $mapping[$field] = $bestHeader;
        }

        if (! $mapping['business_name'] && count($candidates) === 1) {
            $mapping['business_name'] = $candidates[0]['original'];
        }

        return $mapping;
    }

    /**
     * @param  array<string, string|null>  $primary
     * @param  array<string, string|null>  $secondary
     * @return array<string, string|null>
     */
    public function mergeMappings(array $primary, array $secondary, array $headers): array
    {
        $merged = $this->emptyMapping();

        foreach (array_keys($merged) as $key) {
            $merged[$key] = $this->matchHeaderToHeaders($secondary[$key] ?? null, $headers)
                ?? $this->matchHeaderToHeaders($primary[$key] ?? null, $headers);
        }

        return $merged;
    }

    public function matchHeaderToHeaders(?string $candidate, array $headers): ?string
    {
        if ($candidate === null || trim($candidate) === '') {
            return null;
        }

        $candidate = trim($candidate);
        $normalizedCandidate = $this->normalizeHeaderValue($candidate);

        foreach ($headers as $header) {
            $header = trim((string) $header);
            if ($header === '') {
                continue;
            }

            if ($header === $candidate || $this->normalizeHeaderValue($header) === $normalizedCandidate) {
                return $header;
            }
        }

        return null;
    }

    /**
     * @return array<string, string|null>
     */
    protected function requestAiMapping(string $filePath, ?string $sheetName, array $headers): array
    {
        try {
            $reader = IOFactory::createReaderForFile($filePath);
            $reader->setReadDataOnly(true);

            if ($sheetName) {
                $reader->setLoadSheetsOnly([$sheetName]);
            }

            $spreadsheet = $reader->load($filePath);
            $sheet = $spreadsheet->getActiveSheet();

            $preview = [];
            foreach ($sheet->getRowIterator(1, 12) as $row) {
                $cellIterator = $row->getCellIterator();
                $cellIterator->setIterateOnlyExistingCells(false);
                $rowData = [];
                foreach ($cellIterator as $cell) {
                    $rowData[] = SpreadsheetText::normalize($cell->getValue());
                }
                $preview[] = $rowData;
            }

            $headerIndex = (int) SpreadsheetHeaderDetector::detect($preview)['index'];
            $dataRows = [];
            foreach (array_slice($preview, $headerIndex + 1, 4) as $rowData) {
                $dataRows[] = $rowData;
            }

            $systemPrompt = "You are an AI data mapper. Map the uploaded spreadsheet headers to these target system keys:\n".
                "- business_name (Business, company, trade name, account, merchant, shop, firm, name, client)\n".
                "- address (Street address, line 1, physical address)\n".
                "- city (City, town)\n".
                "- state (State, province, region)\n".
                "- zip_code (Zip code, postal code, zip)\n".
                "- country (Country)\n".
                "- website (Website, domain, url)\n".
                "- input_phone (Phone number, mobile, contact phone, contact number)\n".
                "- input_email (Email address, contact email)\n".
                "- owner_name (Owner name, owner, contact name)\n\n".
                "STRICT RULES:\n".
                "1. You MUST find a match for business_name. If no explicit 'business_name' is present, use the column containing the name of the store, company, name of client, or row name.\n".
                "2. Respond with a raw JSON object where keys are the target system keys listed above and values are the exact matching headers from the uploaded file (or null if not found).\n".
                "3. Do not include any explanation or markdown block wrappers. Output ONLY the raw JSON.";

            $userPrompt = 'Uploaded Headers: '.json_encode(array_values($headers))."\n".
                "Sample Rows:\n".json_encode($dataRows);

            $options = [
                'models' => [config('gemini.fallback_models.0', 'gemini-2.5-flash'), 'gemini-2.0-flash', 'gemini-2.5-pro'],
                'google_search_enabled' => false,
                'thinking_budget' => 0,
            ];

            $result = $this->gemini->researchWithGoogleSearch($systemPrompt, $userPrompt, $options);
            $content = $result['content'];

            $firstBrace = strpos($content, '{');
            $lastBrace = strrpos($content, '}');
            if ($firstBrace !== false && $lastBrace !== false) {
                $jsonStr = substr($content, $firstBrace, $lastBrace - $firstBrace + 1);
                $mapping = json_decode(trim($jsonStr), true);
            } else {
                $content = preg_replace('/```json\s*|```\s*/i', '', $content);
                $mapping = json_decode(trim($content), true);
            }

            if (! is_array($mapping)) {
                Log::warning('WorkflowAiMapper failed to parse JSON from AI', ['content' => $content]);

                return $this->emptyMapping();
            }

            $cleanMapping = $this->emptyMapping();
            foreach (array_keys($cleanMapping) as $key) {
                $cleanMapping[$key] = $this->matchHeaderToHeaders($mapping[$key] ?? null, $headers);
            }

            return $cleanMapping;
        } catch (\Throwable $e) {
            Log::warning('WorkflowAiMapper AI mapping unavailable, using heuristics', [
                'error' => $e->getMessage(),
            ]);

            return $this->emptyMapping();
        }
    }

    /**
     * @return array<string, string|null>
     */
    protected function emptyMapping(): array
    {
        return [
            'business_name' => null,
            'address' => null,
            'city' => null,
            'state' => null,
            'zip_code' => null,
            'country' => null,
            'website' => null,
            'input_phone' => null,
            'input_email' => null,
            'owner_name' => null,
        ];
    }

    /**
     * @return array<int, array{original: string, normalized: string}>
     */
    protected function normalizeHeaders(array $headers): array
    {
        $candidates = [];

        foreach ($headers as $header) {
            $original = trim((string) $header);
            if ($original === '') {
                continue;
            }

            $candidates[] = [
                'original' => $original,
                'normalized' => $this->normalizeHeaderValue($original),
            ];
        }

        return $candidates;
    }

    protected function normalizeHeaderValue(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[\s_\-]+/', ' ', $value) ?? $value;

        return trim($value);
    }

    protected function isExcludedBusinessNameHeader(string $normalizedHeader): bool
    {
        foreach (['owner', 'contact', 'first name', 'last name', 'full name', 'rep', 'agent', 'email', 'phone', 'username'] as $needle) {
            if (str_contains($normalizedHeader, $needle)) {
                return true;
            }
        }

        return false;
    }

    protected function scoreHeaderMatch(string $normalizedHeader, string $pattern, int $baseScore): int
    {
        if ($normalizedHeader === $pattern) {
            return $baseScore + 20;
        }

        if (str_starts_with($normalizedHeader, $pattern.' ') || str_ends_with($normalizedHeader, ' '.$pattern)) {
            return $baseScore + 10;
        }

        if (str_contains($normalizedHeader, $pattern)) {
            return $baseScore;
        }

        return 0;
    }
}
