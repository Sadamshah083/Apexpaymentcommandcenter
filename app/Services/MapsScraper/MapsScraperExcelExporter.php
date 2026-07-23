<?php

namespace App\Services\MapsScraper;

use App\Support\UsAreaCodeState;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use ZipArchive;

/**
 * Split scraped lead rows into Excel files grouped by phone area code (first 3 digits).
 */
class MapsScraperExcelExporter
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{zip_path: string, file_count: int, row_count: int, groups: array<string, int>}
     */
    public function exportGroupedByAreaCode(array $rows, string $destinationDir, string $zipBasename): array
    {
        if ($rows === []) {
            throw new RuntimeException('No rows with data to export.');
        }

        $groups = [];
        foreach ($rows as $row) {
            $phone = (string) ($row['phone_number'] ?? $row['phone'] ?? '');
            $area = UsAreaCodeState::areaCodeFromPhone($phone) ?: 'unknown';
            $groups[$area][] = $row;
        }

        ksort($groups);

        if (! is_dir($destinationDir) && ! mkdir($destinationDir, 0755, true) && ! is_dir($destinationDir)) {
            throw new RuntimeException('Unable to create export directory.');
        }

        $excelDir = $destinationDir.DIRECTORY_SEPARATOR.'by_area_code';
        if (! is_dir($excelDir) && ! mkdir($excelDir, 0755, true) && ! is_dir($excelDir)) {
            throw new RuntimeException('Unable to create area-code export directory.');
        }

        $created = [];
        $counts = [];
        foreach ($groups as $areaCode => $groupRows) {
            $stateHint = UsAreaCodeState::stateNameFromAreaCode($areaCode);
            $label = $stateHint
                ? sprintf('%s_%s', $areaCode, $this->slug($stateHint))
                : (string) $areaCode;
            $path = $excelDir.DIRECTORY_SEPARATOR.$label.'.xlsx';
            $this->writeWorkbook($groupRows, $path, (string) $areaCode, $stateHint);
            $created[] = $path;
            $counts[(string) $areaCode] = count($groupRows);
        }

        $zipPath = $destinationDir.DIRECTORY_SEPARATOR.$zipBasename.'.zip';
        $this->zipFiles($created, $zipPath);

        return [
            'zip_path' => $zipPath,
            'file_count' => count($created),
            'row_count' => count($rows),
            'groups' => $counts,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function readCsv(string $csvPath): array
    {
        if (! is_file($csvPath)) {
            throw new RuntimeException('CSV file not found: '.$csvPath);
        }

        $handle = fopen($csvPath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Unable to open CSV: '.$csvPath);
        }

        $headers = null;
        $rows = [];
        while (($data = fgetcsv($handle)) !== false) {
            if ($headers === null) {
                $headers = array_map(fn ($h) => strtolower(trim((string) $h)), $data);
                continue;
            }
            if ($this->rowIsEmpty($data)) {
                continue;
            }
            $row = [];
            foreach ($headers as $i => $header) {
                $row[$header] = $data[$i] ?? '';
            }
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }

    /**
     * Keep rows that look like small / independent businesses.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function filterSmallBusinesses(array $rows): array
    {
        return array_values(array_filter($rows, function (array $row): bool {
            $name = strtolower((string) ($row['name'] ?? ''));
            $type = strtolower((string) ($row['place_type'] ?? ''));

            foreach ([' llc', ' l.l.c.', ' inc', ' corp', ' corporation', ' holdings', ' franchise'] as $marker) {
                if (str_contains($name, $marker)) {
                    return false;
                }
            }

            foreach (['corporate office', 'headquarters', 'wholesale', 'distributor'] as $marker) {
                if (str_contains($type, $marker)) {
                    return false;
                }
            }

            $reviews = $row['reviews_count'] ?? null;
            if ($reviews !== null && $reviews !== '' && is_numeric($reviews) && (int) $reviews > 2500) {
                // Very high review counts often indicate chains / big brands.
                return false;
            }

            $phone = trim((string) ($row['phone_number'] ?? $row['phone'] ?? ''));

            return $phone !== '';
        }));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function writeWorkbook(array $rows, string $path, string $areaCode, ?string $stateHint): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(substr('NPA '.$areaCode, 0, 31));

        $headers = [
            'name', 'phone_number', 'area_code', 'state_from_area_code', 'address', 'website', 'email',
            'place_type', 'reviews_count', 'reviews_average', 'search_city', 'search_state', 'search_business',
        ];
        foreach ($headers as $col => $header) {
            $sheet->setCellValue([$col + 1, 1], $header);
        }

        $r = 2;
        foreach ($rows as $row) {
            $phone = (string) ($row['phone_number'] ?? $row['phone'] ?? '');
            $values = [
                (string) ($row['name'] ?? ''),
                $phone,
                $areaCode,
                $stateHint ?? '',
                (string) ($row['address'] ?? ''),
                (string) ($row['website'] ?? ''),
                (string) ($row['email'] ?? ''),
                (string) ($row['place_type'] ?? ''),
                $row['reviews_count'] ?? '',
                $row['reviews_average'] ?? '',
                (string) ($row['search_city'] ?? ''),
                (string) ($row['search_state'] ?? ''),
                (string) ($row['search_business'] ?? ''),
            ];
            foreach ($values as $col => $value) {
                $sheet->setCellValue([$col + 1, $r], $value);
            }
            $r++;
        }

        $writer = new Xlsx($spreadsheet);
        $writer->save($path);
        $spreadsheet->disconnectWorksheets();
    }

    /**
     * @param  list<string>  $files
     */
    private function zipFiles(array $files, string $zipPath): void
    {
        if (is_file($zipPath)) {
            @unlink($zipPath);
        }

        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create ZIP archive.');
        }

        foreach ($files as $file) {
            $zip->addFile($file, basename($file));
        }
        $zip->close();
    }

    private function slug(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug) ?? '';

        return trim($slug, '_') ?: 'state';
    }

    /**
     * @param  list<mixed>  $data
     */
    private function rowIsEmpty(array $data): bool
    {
        foreach ($data as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }
}
