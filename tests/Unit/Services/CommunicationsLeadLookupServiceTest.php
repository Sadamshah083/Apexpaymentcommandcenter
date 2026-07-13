<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowLead;
use App\Models\Workspace;
use App\Services\Communications\CommunicationsLeadLookupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommunicationsLeadLookupServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_map_labels_for_phones_matches_normalized_lead_phone(): void
    {
        $admin = User::factory()->create();
        $workspace = Workspace::create([
            'name' => 'Test Workspace',
            'admin_id' => $admin->id,
        ]);
        $workflow = Workflow::create([
            'workspace_id' => $workspace->id,
            'name' => 'Test Import',
            'status' => 'completed',
            'processing_mode' => 'full_pipeline',
        ]);
        WorkflowLead::create([
            'workflow_id' => $workflow->id,
            'row_number' => 1,
            'business_name' => 'Balitech Pharmacy',
            'owner_name' => 'Jane Doe',
            'normalized_phone' => '12722001232',
            'input_phone' => '+1 (272) 200-1232',
            'status' => 'completed',
            'pipeline_phase' => 'with_setter',
        ]);

        $service = app(CommunicationsLeadLookupService::class);
        $labels = $service->mapLabelsForPhones($workspace, ['+12722001232']);

        $this->assertArrayHasKey('12722001232', $labels);
        $this->assertSame('Balitech Pharmacy', $labels['12722001232']['name']);
        $this->assertSame('Jane Doe', $labels['12722001232']['contact']);
    }

    public function test_format_phone_display_normalizes_us_numbers(): void
    {
        $this->assertSame(
            '+1 (272) 200-1232',
            CommunicationsLeadLookupService::formatPhoneDisplay('+12722001232'),
        );
    }
}
