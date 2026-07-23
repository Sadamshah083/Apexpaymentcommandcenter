<?php

namespace Tests\Unit\Services\Pipeline;

use App\Models\CommunicationCallLog;
use App\Models\LeadCampaign;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowLead;
use App\Models\Workspace;
use App\Services\Pipeline\CampaignKpiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignKpiServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_summarizes_dials_connected_and_dispositions_by_campaign(): void
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create();
        $campaign = LeadCampaign::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Test Campaign',
            'status' => 'active',
            'created_by' => $user->id,
        ]);
        $workflow = Workflow::factory()->create([
            'workspace_id' => $workspace->id,
            'campaign_id' => $campaign->id,
            'created_by' => $user->id,
        ]);
        $lead = WorkflowLead::factory()->create([
            'workflow_id' => $workflow->id,
            'campaign_id' => $campaign->id,
            'assigned_user_id' => $user->id,
        ]);

        CommunicationCallLog::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'direction' => 'outbound',
            'to_phone' => '15551234567',
            'disposition' => 'No Answer',
            'duration_sec' => 0,
            'status' => 'no-answer',
            'started_at' => now(),
            'meta' => ['lead_id' => $lead->id, 'call_result' => 'no-answer'],
        ]);
        CommunicationCallLog::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'direction' => 'outbound',
            'to_phone' => '15557654321',
            'disposition' => 'Interested',
            'duration_sec' => 42,
            'status' => 'connected',
            'started_at' => now(),
            'meta' => ['lead_id' => $lead->id, 'call_result' => 'connected'],
        ]);

        $kpis = app(CampaignKpiService::class)->forCampaign($workspace, $campaign->id);

        $this->assertSame(2, $kpis['dials']);
        $this->assertSame(1, $kpis['connected']);
        $this->assertSame(50.0, $kpis['connect_rate']);
        $this->assertSame(2, $kpis['dispositioned']);
        $this->assertSame('Interested', $kpis['dispositions'][0]['label']);
    }
}
