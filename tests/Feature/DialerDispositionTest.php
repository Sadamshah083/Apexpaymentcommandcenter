<?php

namespace Tests\Feature;

use App\Models\CommunicationCallLog;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowLead;
use App\Models\Workspace;
use App\Services\Communications\CommunicationsCallHistoryService;
use App\Services\Communications\CommunicationsInboxService;
use App\Services\Integrations\ZoomApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class DialerDispositionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'integrations.morpheus.api_key' => 'ck_test_key',
            'integrations.morpheus.host' => 'apexone.morpheus.cx',
        ]);
    }

    public function test_admin_disposition_saves_call_log_and_removes_lead_from_callable_queue(): void
    {
        $this->mockZoomDisposition();

        $admin = $this->makeAdmin();
        $workspace = Workspace::query()->findOrFail($admin->current_workspace_id);

        $workflow = Workflow::create([
            'workspace_id' => $workspace->id,
            'name' => 'Import A',
            'status' => 'completed',
            'processing_mode' => 'full_pipeline',
        ]);

        $lead = WorkflowLead::create([
            'workflow_id' => $workflow->id,
            'row_number' => 1,
            'business_name' => 'Auto Masters Repair',
            'owner_name' => 'Owner',
            'status' => 'completed',
            'normalized_phone' => '+17062233188',
            'direct_phone' => '+17062233188',
            'contact_attempts' => 0,
            'last_contacted_at' => null,
            'assigned_user_id' => $admin->id,
        ]);

        $duplicate = WorkflowLead::create([
            'workflow_id' => $workflow->id,
            'row_number' => 2,
            'business_name' => 'Auto Masters Repair Dup',
            'owner_name' => 'Owner',
            'status' => 'completed',
            'normalized_phone' => '+17062233188',
            'direct_phone' => '+17062233188',
            'contact_attempts' => 0,
            'last_contacted_at' => null,
            'assigned_user_id' => $admin->id,
        ]);

        $started = microtime(true);

        $response = $this->actingAs($admin)->postJson(route('admin.communications.dialer.disposition'), [
            'disposition' => 'No Answer',
            'call_uuid' => 'test-uuid-disposition-1',
            'lead_id' => $lead->id,
            'phone' => '+17062233188',
            'note' => 'Will try later',
            'duration_sec' => 0,
        ]);

        $elapsedMs = (microtime(true) - $started) * 1000;

        $response->assertOk()
            ->assertJsonPath('saved', true)
            ->assertJsonPath('disposition', 'No Answer')
            ->assertJsonPath('lead_removed', true)
            ->assertJsonPath('lead.id', $lead->id)
            ->assertJsonPath('next_call_delay_sec', (int) config('integrations.communications.next_call_delay_sec', 6))
            ->assertJsonPath('call_log.disposition', 'No Answer')
            ->assertJsonPath('call_log.phone', '+17062233188')
            ->assertJsonPath('call_log.lead_name', 'Auto Masters Repair');

        // Optimized path should be well under the old ~5s enrich bottleneck.
        $this->assertLessThan(2000, $elapsedMs, 'Disposition API should respond quickly');

        $this->assertDatabaseHas('communication_call_logs', [
            'workspace_id' => $workspace->id,
            'morpheus_call_uuid' => 'test-uuid-disposition-1',
            'disposition' => 'No Answer',
            'to_phone' => '+17062233188',
        ]);

        $lead->refresh();
        $duplicate->refresh();
        $this->assertNotNull($lead->last_contacted_at);
        $this->assertGreaterThanOrEqual(1, (int) $lead->contact_attempts);
        $this->assertNotNull($duplicate->last_contacted_at);
        $this->assertSame('No Answer', (string) $lead->last_disposition);

        $imported = app(\App\Services\Communications\DialerImportedLeadsService::class)
            ->paginate($workspace, ['pool' => 'callable'], 0, 50);
        $ids = collect($imported['leads'])->pluck('id')->all();
        $this->assertNotContains($lead->id, $ids);
        $this->assertNotContains($duplicate->id, $ids);

        // Call-log display must keep agent disposition "No Answer" (not treat it as CDR status).
        $hubLog = app(CommunicationsCallHistoryService::class)->toHubLogPublic(
            CommunicationCallLog::query()->where('morpheus_call_uuid', 'test-uuid-disposition-1')->firstOrFail()
        );
        $this->assertSame('No Answer', $hubLog['disposition']);
        $this->assertSame('No Answer', $this->resolveDialerDispositionLabel([
            'disposition' => 'No Answer',
            'result' => 'no-answer',
        ]));
    }

    public function test_no_answer_disposition_renders_in_call_log_row(): void
    {
        $html = view('communications.partials.call-log-row', [
            'log' => [
                'id' => 'local:1',
                'direction' => 'outbound',
                'to' => '+17062233188',
                'to_phone' => '+17062233188',
                'result' => 'no-answer',
                'disposition' => 'No Answer',
                'duration' => 0,
                'time_ago' => 'just now',
                'note' => null,
                'in_call_notes' => null,
            ],
        ])->render();

        $this->assertStringContainsString('data-log-disposition', $html);
        $this->assertStringContainsString('No Answer', $html);
        $this->assertStringContainsString('Disposition:', $html);
    }

    public function test_cdr_no_answer_status_is_not_shown_as_disposition(): void
    {
        $this->assertNull($this->resolveDialerDispositionLabel([
            'disposition' => 'no-answer',
            'result' => 'no-answer',
        ]));
        $this->assertSame('Call Back', $this->resolveDialerDispositionLabel([
            'disposition' => 'Call Back',
            'result' => 'connected',
        ]));
    }

    public function test_disposition_requires_value(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)
            ->postJson(route('admin.communications.dialer.disposition'), [
                'phone' => '+17062233188',
            ]);

        $this->assertTrue(
            in_array($response->getStatusCode(), [422, 302], true),
            'Expected validation failure, got '.$response->getStatusCode().': '.$response->getContent()
        );
    }

    public function test_disposition_updates_existing_call_log_by_uuid(): void
    {
        $this->mockZoomDisposition();

        $admin = $this->makeAdmin();
        $workspace = Workspace::query()->findOrFail($admin->current_workspace_id);

        CommunicationCallLog::create([
            'workspace_id' => $workspace->id,
            'user_id' => $admin->id,
            'morpheus_call_uuid' => 'existing-uuid-1',
            'direction' => 'outbound',
            'to_phone' => '+17063530059',
            'status' => 'initiated',
            'duration_sec' => 12,
            'started_at' => now()->subSeconds(12),
            'meta' => ['source' => 'originate'],
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.communications.dialer.disposition'), [
                'disposition' => 'Owner Hung Up',
                'call_uuid' => 'existing-uuid-1',
                'phone' => '+17063530059',
                'duration_sec' => 12,
            ])
            ->assertOk()
            ->assertJsonPath('saved', true)
            ->assertJsonPath('disposition', 'Owner Hung Up')
            ->assertJsonPath('lead_removed', false)
            ->assertJsonPath('next_call_delay_sec', (int) config('integrations.communications.next_call_delay_sec', 6));

        $this->assertEquals(1, CommunicationCallLog::query()->where('morpheus_call_uuid', 'existing-uuid-1')->count());
        $this->assertDatabaseHas('communication_call_logs', [
            'morpheus_call_uuid' => 'existing-uuid-1',
            'disposition' => 'Owner Hung Up',
            'status' => 'completed',
        ]);
    }

    protected function makeAdmin(): User
    {
        $admin = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Acme Dialer', 'admin_id' => $admin->id]);
        $workspace->users()->attach($admin->id, ['role' => 'admin', 'status' => 'active', 'joined_at' => now()]);
        $admin->update(['current_workspace_id' => $workspace->id]);

        return $admin;
    }

    protected function mockZoomDisposition(): void
    {
        $zoom = Mockery::mock(ZoomApiService::class);
        $zoom->shouldReceive('isConfigured')->andReturn(true);
        $zoom->shouldReceive('dispositionCall')->zeroOrMoreTimes()->andReturn(['ok' => true]);
        $this->app->instance(ZoomApiService::class, $zoom);
    }

    /**
     * @param  array<string, mixed>  $log
     */
    protected function resolveDialerDispositionLabel(array $log): ?string
    {
        $method = new ReflectionMethod(CommunicationsInboxService::class, 'resolveDialerDispositionLabel');
        $method->setAccessible(true);

        return $method->invoke(app(CommunicationsInboxService::class), $log);
    }
}
