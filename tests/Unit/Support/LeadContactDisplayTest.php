<?php

namespace Tests\Unit\Support;

use App\Models\WorkflowLead;
use App\Support\LeadContactDisplay;
use Tests\TestCase;

class LeadContactDisplayTest extends TestCase
{
    public function test_resolves_contact_fields_from_json_report(): void
    {
        $lead = new WorkflowLead([
            'owner_name' => 'Not Publicly Available',
            'direct_phone' => 'Not Publicly Available',
            'direct_email' => 'Not Publicly Available',
            'website' => 'Not Publicly Available',
            'payment_processor' => 'Not Publicly Available',
            'city' => 'Abbeville',
            'markdown_report' => json_encode([
                'business_name' => "Money's Grill, LLC",
                'address' => '1000 Charity St, Abbeville, LA 70510',
                'phone_number' => '(337) 893-0000',
                'owner_name' => 'Monique M. Broussard',
                'owner_email' => 'Not Publicly Available',
                'payment_processor' => 'Not Publicly Available',
            ]),
        ]);

        $contact = LeadContactDisplay::for($lead);

        $this->assertSame('Monique M. Broussard', $contact['owner']);
        $this->assertSame('(337) 893-0000', $contact['phone']);
        $this->assertSame('1000 Charity St, Abbeville, LA 70510', $contact['address']);
        $this->assertSame('Abbeville', $contact['location']);
    }

    public function test_hides_fenced_json_enrichment_report_from_ui(): void
    {
        $report = "```json\n".json_encode([
            'business_name' => "Money's Grill, LLC",
            'phone_number' => '(337) 893-0000',
        ], JSON_PRETTY_PRINT)."\n```";

        $this->assertTrue(LeadContactDisplay::isJsonReport($report));
        $this->assertFalse(LeadContactDisplay::shouldDisplayEnrichmentReport($report));
    }

    public function test_handles_array_values_in_json_report(): void
    {
        $lead = new WorkflowLead([
            'markdown_report' => json_encode([
                'business_name' => "Test Business",
                'phone_number' => ['(337) 893-0000', '(337) 893-0001'],
                'booking_pos_software' => ['Toast', 'Square'],
            ]),
        ]);

        $contact = LeadContactDisplay::for($lead);

        $this->assertSame('(337) 893-0000, (337) 893-0001', $contact['phone']);
        $this->assertSame('Toast, Square', $contact['pos_system']);
    }

    public function test_scalar_field_handles_empty_array_without_type_error(): void
    {
        $parser = app(\App\Services\BusinessResearch\MarkdownReportParser::class);
        $parsed = $parser->parseContent(json_encode([
            'business_name' => 'Test Co',
            'phone_number' => [],
            'booking_pos_software' => null,
        ]));

        $this->assertNull($parsed['direct_phone']);
        $this->assertNull($parsed['system_integration']);
    }

    public function test_classifies_facebook_as_social_media_not_website(): void
    {
        $lead = new WorkflowLead([
            'business_name' => 'Dos Hermanos',
            'city' => 'Birmingham',
            'website' => 'facebook.com',
            'raw_row' => [
                'name' => 'Dos Hermanos (Taco Truck)',
                'website' => 'facebook.com',
                'phone_number' => null,
            ],
        ]);

        $contact = LeadContactDisplay::for($lead);

        $this->assertSame('facebook.com', $contact['social_media']);
        $this->assertNull($contact['website']);
        $this->assertNull($contact['phone']);
        $this->assertNull($contact['email']);
    }

    public function test_keeps_business_website_separate_from_social_media(): void
    {
        $lead = new WorkflowLead([
            'business_name' => 'Example Cafe',
            'website' => 'https://www.examplecafe.com',
            'raw_row' => [
                'website' => 'https://www.examplecafe.com',
                'facebook' => 'facebook.com/examplecafe',
            ],
        ]);

        $contact = LeadContactDisplay::for($lead);

        $this->assertSame('https://www.examplecafe.com', $contact['website']);
        $this->assertSame('facebook.com/examplecafe', $contact['social_media']);
    }
}
