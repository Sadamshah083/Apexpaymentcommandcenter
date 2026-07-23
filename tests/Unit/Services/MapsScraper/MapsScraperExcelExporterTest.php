<?php

namespace Tests\Unit\Services\MapsScraper;

use App\Services\MapsScraper\MapsScraperExcelExporter;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;
use ZipArchive;

class MapsScraperExcelExporterTest extends TestCase
{
    public function test_groups_phones_into_excel_files_by_area_code(): void
    {
        $exporter = new MapsScraperExcelExporter;
        $dir = storage_path('framework/testing/maps-exporter-'.uniqid());
        mkdir($dir, 0755, true);

        $rows = [
            [
                'name' => 'Birmingham Lock Pros',
                'phone_number' => '(334) 555-1212',
                'address' => '1 Main St, Montgomery, AL, USA',
                'place_type' => 'Locksmith',
                'reviews_count' => 12,
            ],
            [
                'name' => 'Mobile Keys',
                'phone_number' => '251-555-9898',
                'address' => '2 Oak Ave, Mobile, AL, USA',
                'place_type' => 'Locksmith',
                'reviews_count' => 8,
            ],
            [
                'name' => 'Same Area Shop',
                'phone_number' => '+1 334-555-0001',
                'address' => '3 Pine Rd, Auburn, AL, USA',
                'place_type' => 'Key maker',
                'reviews_count' => 3,
            ],
        ];

        $result = $exporter->exportGroupedByAreaCode($rows, $dir, 'test_area_codes');

        $this->assertFileExists($result['zip_path']);
        $this->assertSame(2, $result['file_count']);
        $this->assertSame(3, $result['row_count']);
        $this->assertSame(2, $result['groups']['334']);
        $this->assertSame(1, $result['groups']['251']);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($result['zip_path']));
        $this->assertGreaterThanOrEqual(2, $zip->numFiles);
        $zip->close();

        $xlsx = glob($dir.'/by_area_code/334_*.xlsx');
        $this->assertNotEmpty($xlsx);
        $sheet = IOFactory::load($xlsx[0])->getActiveSheet();
        $this->assertSame('phone_number', $sheet->getCell('B1')->getValue());
        $this->assertSame(3, $sheet->getHighestRow()); // header + 2 rows for 334

        // cleanup
        foreach (glob($dir.'/by_area_code/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir.'/by_area_code');
        @unlink($result['zip_path']);
        @rmdir($dir);
    }

    public function test_filters_out_corporate_and_phoneless_rows(): void
    {
        $exporter = new MapsScraperExcelExporter;
        $filtered = $exporter->filterSmallBusinesses([
            ['name' => 'Acme Lock LLC', 'phone_number' => '3345551212', 'place_type' => 'Locksmith', 'reviews_count' => 10],
            ['name' => 'Local Key Shop', 'phone_number' => '3345559999', 'place_type' => 'Locksmith', 'reviews_count' => 10],
            ['name' => 'No Phone Shop', 'phone_number' => '', 'place_type' => 'Locksmith', 'reviews_count' => 2],
            ['name' => 'Mega Brand', 'phone_number' => '2055551111', 'place_type' => 'Locksmith', 'reviews_count' => 9000],
        ]);

        $this->assertCount(1, $filtered);
        $this->assertSame('Local Key Shop', $filtered[0]['name']);
    }
}
