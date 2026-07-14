<?php

namespace Tests\Unit\Services;

use App\Services\Communications\CallMonitoringService;
use App\Services\Communications\CommunicationsAgentService;
use App\Services\Communications\MorpheusCallEventService;
use App\Services\Communications\MorpheusHubService;
use App\Services\Integrations\ZoomApiService;
use App\Services\Workspace\WorkspaceContextService;
use Mockery;
use Tests\TestCase;

class CallMonitoringServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_ringing_stays_in_ringing_table_with_zero_timer(): void
    {
        $snapshot = $this->snapshotFromLiveStates([[
            'uuid' => 'probe-ring-1',
            'live' => true,
            'destination_answered' => false,
            'destination_connected' => false,
            'destination' => '2092592594',
            'from_extension' => '1015',
            'billsec' => 0,
            'updated_at' => now()->toIso8601String(),
        ]]);

        $this->assertSame(1, $snapshot['summary']['ringing']);
        $this->assertSame(0, $snapshot['summary']['in_call_short']);
        $this->assertSame(0, $snapshot['summary']['in_call_long']);
        $this->assertCount(1, $snapshot['tables']['ringing']);
        $this->assertCount(0, $snapshot['tables']['incall_short']);
        $this->assertCount(0, $snapshot['tables']['incall_long']);

        $row = $snapshot['tables']['ringing'][0];
        $this->assertSame('ringing', $row['bucket']);
        $this->assertSame('RINGING', $row['status']);
        $this->assertSame(0, $row['timer_sec']);
    }

    public function test_connected_under_two_minutes_goes_to_short_table_with_timer(): void
    {
        $connectedAt = now()->subSeconds(41)->toIso8601String();
        $snapshot = $this->snapshotFromLiveStates([[
            'uuid' => 'probe-short-1',
            'live' => true,
            'destination_answered' => true,
            'destination_connected' => true,
            'connected_at' => $connectedAt,
            'destination' => '2092592594',
            'from_extension' => '1015',
            'billsec' => 41,
            'updated_at' => now()->toIso8601String(),
        ]]);

        $this->assertSame(0, $snapshot['summary']['ringing']);
        $this->assertSame(1, $snapshot['summary']['in_call_short']);
        $this->assertSame(0, $snapshot['summary']['in_call_long']);
        $this->assertCount(1, $snapshot['tables']['incall_short']);

        $row = $snapshot['tables']['incall_short'][0];
        $this->assertSame('incall_short', $row['bucket']);
        $this->assertSame('INCALL ≤2M', $row['status']);
        $this->assertGreaterThanOrEqual(40, $row['timer_sec']);
        $this->assertLessThanOrEqual(45, $row['timer_sec']);
    }

    public function test_connected_over_two_minutes_goes_to_long_table(): void
    {
        $connectedAt = now()->subSeconds(150)->toIso8601String();
        $snapshot = $this->snapshotFromLiveStates([[
            'uuid' => 'probe-long-1',
            'live' => true,
            'destination_answered' => true,
            'destination_connected' => true,
            'connected_at' => $connectedAt,
            'destination' => '2092592594',
            'from_extension' => '1015',
            'billsec' => 150,
            'updated_at' => now()->toIso8601String(),
        ]]);

        $this->assertSame(0, $snapshot['summary']['ringing']);
        $this->assertSame(0, $snapshot['summary']['in_call_short']);
        $this->assertSame(1, $snapshot['summary']['in_call_long']);
        $this->assertCount(1, $snapshot['tables']['incall_long']);

        $row = $snapshot['tables']['incall_long'][0];
        $this->assertSame('incall_long', $row['bucket']);
        $this->assertSame('INCALL >2M', $row['status']);
        $this->assertGreaterThan(120, $row['timer_sec']);
    }

    public function test_active_hub_call_without_destination_answer_is_still_ringing(): void
    {
        $hub = Mockery::mock(MorpheusHubService::class);
        $hub->shouldReceive('activeCallsFresh')->andReturn([[
            'uuid' => 'hub-active-only',
            'status' => 'ringing',
            'age_sec' => 55,
            'billsec' => 0,
            'destination_number' => '2092592594',
            'caller_id_number' => '1015',
        ]]);

        $events = Mockery::mock(MorpheusCallEventService::class);
        $events->shouldReceive('listLiveStates')->andReturn([]);
        $events->shouldReceive('getCallState')->andReturn(null);
        $events->shouldReceive('monitoringVersion')->andReturn(1);
        $events->shouldReceive('markDestinationConnected')->never();

        $this->mockQuietHubStatus();

        $snapshot = $this->makeService($hub, $events)->snapshot(null);

        $this->assertCount(1, $snapshot['tables']['ringing']);
        $this->assertSame(0, $snapshot['tables']['ringing'][0]['timer_sec']);
        $this->assertSame('RINGING', $snapshot['tables']['ringing'][0]['status']);
        $this->assertSame(0, $snapshot['summary']['in_call']);
    }

    public function test_webhook_answer_event_sets_connected_and_starts_timer_path(): void
    {
        $events = app(MorpheusCallEventService::class);
        $uuid = 'unit-answer-'.uniqid();

        $events->watchCall($uuid, '1015', '2092592594');
        $events->ingestWebhook([
            'event' => 'destination_answered',
            'call_uuid' => $uuid,
            'destination_number' => '2092592594',
            'billsec' => 1,
        ]);

        $state = $events->getCallState($uuid);
        $this->assertNotNull($state);
        $this->assertTrue((bool) ($state['destination_answered'] ?? false));
        $this->assertNotEmpty($state['connected_at'] ?? null);

        $events->markCallEnded($uuid, 'NORMAL_CLEARING', 1);
    }

    public function test_mark_destination_connected_promotes_ringing_to_incall_short(): void
    {
        $events = app(MorpheusCallEventService::class);
        $uuid = 'unit-mark-conn-'.uniqid();
        $events->watchCall($uuid, '1015', '2092592594');
        $events->markDestinationConnected($uuid, '2092592594', 5, 'agent');

        $hub = Mockery::mock(MorpheusHubService::class);
        $hub->shouldReceive('activeCallsFresh')->andReturn([]);

        $agents = Mockery::mock(CommunicationsAgentService::class);
        $agents->shouldReceive('listForWorkspace')->andReturn([]);
        $workspaceContext = Mockery::mock(WorkspaceContextService::class);
        $workspaceContext->shouldReceive('resolveActiveWorkspace')->andReturn(null);

        $this->mockQuietHubStatus();

        $snapshot = (new CallMonitoringService($hub, $agents, $workspaceContext, $events))->snapshot(null);
        $row = collect($snapshot['rows'])->firstWhere('id', $uuid);

        $this->assertNotNull($row);
        $this->assertSame('incall_short', $row['bucket']);
        $this->assertGreaterThan(0, $row['timer_sec']);

        $events->markCallEnded($uuid, 'NORMAL_CLEARING', 5);
    }

    public function test_duplicate_ringing_legs_collapse_to_one_row(): void
    {
        $snapshot = $this->snapshotFromLiveStates([
            [
                'uuid' => 'dup-a',
                'live' => true,
                'destination_answered' => false,
                'destination_connected' => false,
                'destination' => '12092592594',
                'from_extension' => '1015',
                'billsec' => 0,
                'updated_at' => now()->toIso8601String(),
            ],
            [
                'uuid' => 'dup-b',
                'live' => true,
                'destination_answered' => false,
                'destination_connected' => false,
                'destination' => '2092592594',
                'from_extension' => '1015',
                'billsec' => 0,
                'updated_at' => now()->toIso8601String(),
            ],
            [
                'uuid' => 'dup-c',
                'live' => true,
                'destination_answered' => false,
                'destination_connected' => false,
                'destination' => '+12092592594',
                'from_extension' => '1015',
                'billsec' => 0,
                'updated_at' => now()->toIso8601String(),
            ],
        ]);

        $this->assertSame(1, $snapshot['summary']['ringing']);
        $this->assertCount(1, $snapshot['tables']['ringing']);
        $this->assertCount(1, $snapshot['rows']);
    }

    public function test_watch_call_supersedes_prior_live_leg(): void
    {
        $events = app(MorpheusCallEventService::class);
        $first = 'unit-super-a-'.uniqid();
        $second = 'unit-super-b-'.uniqid();

        $events->watchCall($first, '1015', '2092592594');
        $events->watchCall($second, '1015', '2092592594');

        $firstState = $events->getCallState($first);
        $secondState = $events->getCallState($second);

        $this->assertFalse((bool) ($firstState['live'] ?? true));
        $this->assertTrue((bool) ($secondState['live'] ?? false));

        $events->markCallEnded($second, 'NORMAL_CLEARING', 0);
    }

    /**
     * @param  array<int, array<string, mixed>>  $states
     * @return array<string, mixed>
     */
    protected function snapshotFromLiveStates(array $states): array
    {
        $hub = Mockery::mock(MorpheusHubService::class);
        $hub->shouldReceive('activeCallsFresh')->andReturn([]);

        $events = Mockery::mock(MorpheusCallEventService::class);
        $events->shouldReceive('listLiveStates')->andReturn($states);
        $events->shouldReceive('getCallState')->andReturnUsing(function (string $uuid) use ($states) {
            foreach ($states as $state) {
                if (($state['uuid'] ?? '') === $uuid) {
                    return $state;
                }
            }

            return null;
        });
        $events->shouldReceive('monitoringVersion')->andReturn(7);
        $events->shouldReceive('markDestinationConnected')->andReturnNull();

        $this->mockQuietHubStatus();

        return $this->makeService($hub, $events)->snapshot(null);
    }

    protected function makeService(MorpheusHubService $hub, MorpheusCallEventService $events): CallMonitoringService
    {
        $agents = Mockery::mock(CommunicationsAgentService::class);
        $agents->shouldReceive('listForWorkspace')->andReturn([]);

        $workspaceContext = Mockery::mock(WorkspaceContextService::class);
        $workspaceContext->shouldReceive('resolveActiveWorkspace')->andReturn(null);

        return new CallMonitoringService($hub, $agents, $workspaceContext, $events);
    }

    protected function mockQuietHubStatus(): void
    {
        $this->mock(ZoomApiService::class, function ($mock) {
            $mock->shouldReceive('hubLiveCallStatus')->andReturn([
                'destination_connected' => false,
                'destination_answered' => false,
                'outcome' => 'ringing',
            ]);
        });
    }
}
