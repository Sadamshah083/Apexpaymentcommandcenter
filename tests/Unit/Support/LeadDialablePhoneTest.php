<?php

namespace Tests\Unit\Support;

use App\Models\WorkflowLead;
use App\Support\LeadDialablePhone;
use Tests\TestCase;

class LeadDialablePhoneTest extends TestCase
{
    public function test_resolves_phone_from_markdown_when_columns_are_placeholders(): void
    {
        $lead = new WorkflowLead([
            'direct_phone' => 'Not Publicly Available',
            'input_phone' => null,
            'normalized_phone' => null,
            'markdown_report' => "### Contact\nPhone: (708) 848-2777\n",
        ]);

        $this->assertSame('+17088482777', LeadDialablePhone::resolve($lead));

        $updates = LeadDialablePhone::syncAttributes($lead);
        $this->assertSame('17088482777', $updates['normalized_phone']);
        $this->assertSame('+17088482777', $updates['direct_phone']);
    }

    public function test_ignores_placeholder_only_leads(): void
    {
        $lead = new WorkflowLead([
            'direct_phone' => 'Not Publicly Available',
            'markdown_report' => 'No contact details found.',
        ]);

        $this->assertNull(LeadDialablePhone::resolve($lead));
        $this->assertSame([], LeadDialablePhone::syncAttributes($lead));
    }

    public function test_uses_raw_contact_no(): void
    {
        $lead = new WorkflowLead([
            'direct_phone' => 'Not Publicly Available',
            'raw_row' => ['Contact No.' => '312-555-0199'],
        ]);

        $this->assertSame('+13125550199', LeadDialablePhone::resolve($lead));
    }
}
