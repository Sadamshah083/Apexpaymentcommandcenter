<?php

namespace Tests\Unit\Support;

use App\Support\SpreadsheetHeaderDetector;
use App\Support\SpreadsheetText;
use App\Services\Workflow\WorkflowAiMapper;
use Mockery;
use Tests\TestCase;

class SpreadsheetImportEncodingTest extends TestCase
{
    public function test_repairs_mojibake_em_dash(): void
    {
        $this->assertSame('—', SpreadsheetText::normalize('ΓÇö'));
        $this->assertSame('—', SpreadsheetText::normalize('Гçö'));
        $this->assertSame("O'Reilly", SpreadsheetText::normalize("OΓÇÖReilly"));
    }

    public function test_decodes_html_entities_from_xlsx_exports(): void
    {
        $this->assertSame('Lake\'s Alignment & Truck Service', SpreadsheetText::normalize("Lake's Alignment &amp; Truck Service"));
        $this->assertSame('J & J Auto', SpreadsheetText::normalize('J &amp; J Auto'));
    }

    public function test_detects_b2b_header_row_after_niche_preamble(): void
    {
        $rows = [
            ['Niche: Auto Repair'."\n".'State: GA', '', '', '', ''],
            ['City', 'Business Name', 'Contact Number', 'Address', 'Owner Name'],
            ['East Dublin', 'Rickys Auto Repair', '+1 478-296-0961', '209 Savannah Ave', 'Ricky Rich'],
        ];

        $detected = SpreadsheetHeaderDetector::detect($rows);

        $this->assertSame(1, $detected['index']);
        $this->assertSame('Business Name', $detected['headers'][1]);
        $this->assertSame('Contact Number', $detected['headers'][2]);
    }

    public function test_heuristic_maps_b2b_contact_number_columns(): void
    {
        $mapper = new WorkflowAiMapper(Mockery::mock(\App\Services\BusinessResearch\GeminiClient::class));
        $headers = ['City', 'Business Name', 'Contact Number', 'Address', 'Owner Name'];
        $mapping = $mapper->heuristicMap($headers);

        $this->assertSame('Business Name', $mapping['business_name']);
        $this->assertSame('Contact Number', $mapping['input_phone']);
        $this->assertSame('Address', $mapping['address']);
        $this->assertSame('City', $mapping['city']);
        $this->assertSame('Owner Name', $mapping['owner_name']);
    }
}
