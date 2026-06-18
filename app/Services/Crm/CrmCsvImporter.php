<?php

namespace App\Services\Crm;

use App\Models\CrmCampaign;
use App\Models\CrmLead;

class CrmCsvImporter
{
    public function __construct(
        protected CrmColumnMapper $mapper,
        protected CrmAddressParser $addressParser,
        protected CrmLeadFingerprint $fingerprint,
        protected CrmResearchCopier $copier,
    ) {}

    /**
     * @return array{
     *     imported: int,
     *     updated: int,
     *     cached: int,
     *     skipped: int,
     *     needs_research: array<int, int>,
     *     mapping: array<string, string|null>,
     *     headers: array<int, string>
     * }
     */
    public function import(CrmCampaign $campaign, string $filePath): array
    {
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new \RuntimeException('Could not read CSV file.');
        }

        $headers = fgetcsv($handle);
        if (! $headers || count(array_filter($headers)) === 0) {
            fclose($handle);
            throw new \RuntimeException('CSV has no header row.');
        }

        $headers = array_map(fn ($h) => trim((string) $h), $headers);
        $mapping = $this->mapper->map($headers);
        $extraHeaders = $this->mapper->unmappedHeaders($headers, $mapping);

        $campaign->update([
            'csv_headers' => $headers,
            'column_mapping' => $mapping,
            'status' => 'importing',
        ]);

        $imported = 0;
        $updated = 0;
        $cached = 0;
        $skipped = 0;
        $needsResearch = [];
        $rowNumber = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;

            if (count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }

            $leadData = $this->buildLeadData($campaign->id, $rowNumber, $headers, $row, $mapping, $extraHeaders);

            if ($leadData === null) {
                $skipped++;
                continue;
            }

            $fp = $this->fingerprint->make(
                $leadData['business_name'],
                $leadData['address'],
                $leadData['city'],
                $leadData['state'],
                $leadData['zip_code'],
            );
            $leadData['research_fingerprint'] = $fp;

            $existing = CrmLead::where('campaign_id', $campaign->id)
                ->where('research_fingerprint', $fp)
                ->first();

            if ($existing) {
                $this->updateExistingLead($existing, $leadData, $needsResearch);
                $updated++;
                continue;
            }

            if (config('crm.reuse_research', true)) {
                $source = $this->copier->findCachedSource($fp);
                if ($source) {
                    $lead = CrmLead::create(array_merge($leadData, ['status' => 'pending']));
                    $this->copier->copyToLead($lead, $source);
                    $imported++;
                    $cached++;
                    continue;
                }
            }

            $lead = CrmLead::create(array_merge($leadData, ['status' => 'pending']));
            $needsResearch[] = $lead->id;
            $imported++;
        }

        fclose($handle);

        return [
            'imported' => $imported,
            'updated' => $updated,
            'cached' => $cached,
            'skipped' => $skipped,
            'needs_research' => $needsResearch,
            'mapping' => $mapping,
            'headers' => $headers,
        ];
    }

    /**
     * @param  array<string, mixed>  $leadData
     * @param  array<int, int>  $needsResearch
     */
    protected function updateExistingLead(CrmLead $existing, array $leadData, array &$needsResearch): void
    {
        $inputFields = $this->inputOnly($leadData);
        $inputChanged = $this->copier->inputChanged($existing, $leadData);

        $existing->update(array_merge($inputFields, [
            'row_number' => $leadData['row_number'],
            'research_fingerprint' => $leadData['research_fingerprint'],
        ]));

        if ($existing->status === 'completed' && ! $inputChanged) {
            return;
        }

        if ($existing->status === 'completed' && $inputChanged && config('crm.re_research_on_input_change', true)) {
            $existing->update(['status' => 'pending', 'error_message' => null]);
            $needsResearch[] = $existing->id;

            return;
        }

        if (in_array($existing->status, ['failed', 'pending', 'processing'], true)) {
            if ($existing->status === 'processing') {
                return;
            }
            $needsResearch[] = $existing->id;
        }
    }

    /**
     * @param  array<string, mixed>  $leadData
     * @return array<string, mixed>
     */
    protected function inputOnly(array $leadData): array
    {
        return array_intersect_key($leadData, array_flip([
            'business_name', 'address', 'city', 'state', 'zip_code', 'country',
            'website', 'input_phone', 'input_email', 'raw_row', 'extra_fields',
        ]));
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, string|null>  $row
     * @param  array<string, string|null>  $mapping
     * @param  array<string, string>  $extraHeaders
     * @return array<string, mixed>|null
     */
    protected function buildLeadData(
        int $campaignId,
        int $rowNumber,
        array $headers,
        array $row,
        array $mapping,
        array $extraHeaders,
    ): ?array {
        $raw = [];
        foreach ($headers as $i => $header) {
            $raw[$header] = $this->sanitizeUtf8(trim((string) ($row[$i] ?? '')));
        }

        $get = fn (string $field) => isset($mapping[$field], $raw[$mapping[$field]])
            ? trim($raw[$mapping[$field]])
            : null;

        $businessName = $get('business_name') ?? '';

        if ($businessName === '') {
            return config('crm.skip_invalid_rows', true) ? null : [
                'campaign_id' => $campaignId,
                'row_number' => $rowNumber,
                'status' => 'skipped',
                'business_name' => 'Row '.$rowNumber,
                'raw_row' => $raw,
                'extra_fields' => $this->extractExtra($raw, $extraHeaders),
            ];
        }

        $addressParts = $this->resolveAddress($get);

        return [
            'campaign_id' => $campaignId,
            'row_number' => $rowNumber,
            'business_name' => mb_substr($businessName, 0, 500),
            'address' => $this->truncate($addressParts['address'], 500),
            'city' => $this->truncate($addressParts['city'], 120),
            'state' => $this->truncate($addressParts['state'], 50),
            'zip_code' => $this->truncate($addressParts['zip_code'], 20),
            'country' => $this->truncate($get('country'), 50),
            'website' => $this->normalizeWebsite($get('website')),
            'input_phone' => $this->truncate($get('input_phone'), 50),
            'input_email' => $this->truncate($get('input_email'), 255),
            'raw_row' => $raw,
            'extra_fields' => $this->extractExtra($raw, $extraHeaders),
        ];
    }

    /**
     * @param  callable(string): ?string  $get
     * @return array{address: ?string, city: ?string, state: ?string, zip_code: ?string}
     */
    protected function resolveAddress(callable $get): array
    {
        $street = $get('address');
        $line2 = $get('address_line_2');
        $full = $get('full_address');
        $city = $get('city');
        $state = $get('state');
        $zip = $get('zip_code');

        if ($line2 && $street) {
            $street = trim($street.' '.$line2);
        } elseif ($line2 && ! $street) {
            $street = $line2;
        }

        if ($full) {
            $parsed = $this->addressParser->parse($full);

            return $this->addressParser->mergeParts($parsed, $street, $city, $state, $zip);
        }

        if ($street && ! $city && ! $state && str_contains($street, ',')) {
            $parsed = $this->addressParser->parse($street);

            return $this->addressParser->mergeParts($parsed, null, $city, $state, $zip);
        }

        return [
            'address' => $street,
            'city' => $city,
            'state' => $state,
            'zip_code' => $zip,
        ];
    }

    /**
     * @param  array<string, string>  $raw
     * @param  array<string, string>  $extraHeaders
     * @return array<string, string>
     */
    protected function extractExtra(array $raw, array $extraHeaders): array
    {
        $extra = [];
        foreach ($extraHeaders as $header) {
            $value = trim($raw[$header] ?? '');
            if ($value !== '') {
                $extra[$header] = $value;
            }
        }

        return $extra;
    }

    protected function normalizeWebsite(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        $url = trim($url);
        if ($url === '') {
            return null;
        }

        if (! str_starts_with($url, 'http')) {
            $url = 'https://'.$url;
        }

        return mb_substr($url, 0, 500);
    }

    protected function truncate(?string $value, int $max): ?string
    {
        if (! $value) {
            return null;
        }

        $value = trim($this->sanitizeUtf8($value) ?? '');

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    protected function sanitizeUtf8(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        $clean = iconv('UTF-8', 'UTF-8//IGNORE', $value);

        return $clean === false ? '' : $clean;
    }
}
