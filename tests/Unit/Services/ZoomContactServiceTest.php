<?php

namespace Tests\Unit\Services;

use App\Services\Communications\CommunicationsDataService;
use App\Services\Communications\ZoomContactService;
use App\Services\Integrations\ZoomApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class ZoomContactServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_merge_dedupes_users_and_call_parties(): void
    {
        $zoom = Mockery::mock(ZoomApiService::class);
        $zoom->shouldReceive('isConfigured')->andReturn(true);
        $zoom->shouldReceive('listUsers')->andReturn([
            'users' => [[
                'id' => 'u1',
                'first_name' => 'Mark',
                'last_name' => 'Barrette',
                'email' => 'mark@example.com',
                'type' => 'user',
                'last_login_time' => '2026-06-15T10:00:00Z',
            ]],
            'next_page_token' => null,
        ]);
        $zoom->shouldReceive('listCallLogs')->andReturn([
            'call_logs' => [[
                'id' => 'log1',
                'direction' => 'outbound',
                'caller' => ['name' => 'Prospect', 'phone_number' => '+15715231943'],
                'callee' => ['phone_number' => '+12098526079'],
                'date_time' => '2026-06-16T20:40:07Z',
                'duration' => 120,
                'call_result' => 'connected',
            ]],
            'next_page_token' => null,
        ]);
        $zoom->shouldReceive('normalizeCallLog')->andReturnUsing(function (array $row) {
            return [
                'id' => $row['id'],
                'direction' => strtolower($row['direction'] ?? 'unknown'),
                'from' => 'Prospect',
                'from_phone' => '+15715231943',
                'to' => 'Mark',
                'to_phone' => '+12098526079',
                'start_time' => $row['date_time'] ?? null,
                'duration' => (int) ($row['duration'] ?? 0),
                'result' => $row['call_result'] ?? '—',
                'recording' => '—',
                'raw' => $row,
            ];
        });

        $this->app->instance(ZoomApiService::class, $zoom);

        $payload = app(ZoomContactService::class)->buildIndexPayload();

        $this->assertNull($payload['error']);
        $this->assertGreaterThanOrEqual(2, count($payload['contacts']));
        $this->assertTrue(collect($payload['contacts'])->contains(fn ($c) => $c['contact_key'] === 'user:u1'));
        $this->assertTrue(collect($payload['contacts'])->contains(fn ($c) => str_starts_with($c['contact_key'], 'phone:')));
    }

    public function test_activity_timeline_merges_calls_sms_and_voicemails(): void
    {
        $service = app(ZoomContactService::class);

        $contact = [
            'contact_key' => 'phone:+15551234567',
            'phone' => '+15551234567',
            'name' => 'Prospect',
        ];

        $timeline = $service->activityTimelineFor(
            $contact,
            [[
                'id' => 'log1',
                'direction' => 'inbound',
                'from' => 'Prospect',
                'from_phone' => '+15551234567',
                'to' => 'Agent',
                'to_phone' => '+15557654321',
                'start_time' => '2026-06-16T12:00:00Z',
                'duration' => 60,
                'result' => 'connected',
            ]],
            [[
                'id' => 'vm1',
                'file_id' => 'vm_file_1',
                'caller_number' => '+15551234567',
                'caller' => 'Prospect',
                'callee' => 'Agent',
                'date_time' => '2026-06-16T11:00:00Z',
                'duration' => 20,
                'status' => 'unread',
                'has_media' => true,
            ]],
            [[
                'session_id' => 'sms_sess_1',
                'label' => 'Prospect',
                'other_phone' => '+15551234567',
                'owner_phone' => '+15557654321',
                'last_access_time' => '2026-06-16T13:00:00Z',
            ]],
        );

        $types = collect($timeline)->pluck('type')->all();

        $this->assertContains('call', $types);
        $this->assertContains('sms', $types);
        $this->assertContains('voicemail', $types);
        $this->assertSame('sms_sess_1', collect($timeline)->firstWhere('type', 'sms')['session_id']);
    }
}
