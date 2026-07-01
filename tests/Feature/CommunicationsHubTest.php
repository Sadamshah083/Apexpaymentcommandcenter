<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Communications\ZoomContactService;
use App\Services\Communications\CommunicationsDataService;
use App\Services\Integrations\ZoomApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class CommunicationsHubTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'integrations.morpheus.api_key' => 'ck_test_super_secret_value_1234',
            'integrations.morpheus.host' => 'apexone.morpheus.cx',
        ]);
    }

    public function test_admin_can_open_contacts_hub(): void
    {
        $this->mockZoomServices();

        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get(route('admin.communications.index', ['channel' => 'inbox']))
            ->assertOk()
            ->assertSee('Communications')
            ->assertSee('Mark Barrette')
            ->assertDontSee('super_secret_value_1234');
    }

    public function test_portal_agent_can_open_contacts_hub(): void
    {
        $this->mockZoomServices();

        $owner = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Acme', 'admin_id' => $owner->id]);
        $workspace->users()->attach($owner->id, ['role' => 'admin', 'status' => 'active', 'joined_at' => now()]);

        $agent = User::factory()->create([
            'password' => Hash::make('password123'),
            'current_workspace_id' => $workspace->id,
        ]);
        $workspace->users()->attach($agent->id, ['role' => 'appointment_setter', 'status' => 'active', 'joined_at' => now()]);

        $this->actingAs($agent)
            ->get(route('portal.communications.index'))
            ->assertOk()
            ->assertSee('Communications');
    }

    public function test_zoom_settings_masks_secret(): void
    {
        $zoom = Mockery::mock(ZoomApiService::class);
        $zoom->shouldReceive('isConfigured')->andReturn(true);
        $zoom->shouldReceive('connectionStatus')->andReturn([
            'connected' => true,
            'message' => 'Connected to Morpheus CX API.',
            'expires_at' => null,
        ]);
        $this->mockZoomConnectionDiagnostics($zoom);
        $zoom->shouldReceive('accountId')->andReturn('acct_test');
        $zoom->shouldReceive('clientId')->andReturn('client_test');
        $zoom->shouldReceive('maskedSecret')->andReturn('••••••••1234');
        $zoom->shouldReceive('webhookSecret')->andReturn(null);
        $zoom->shouldReceive('requiredScopes')->andReturn([
            'phone:read:list_call_logs:admin' => 'Account call logs',
        ]);

        $contacts = Mockery::mock(ZoomContactService::class);
        $this->app->instance(ZoomApiService::class, $zoom);
        $this->app->instance(ZoomContactService::class, $contacts);
        $this->mockHubDataService();

        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get(route('admin.communications.index', ['panel' => 'settings']))
            ->assertOk()
            ->assertSee('acct_test')
            ->assertSee('••••••••1234')
            ->assertDontSee('super_secret_value_1234');
    }

    public function test_recording_media_resolves_call_element_id(): void
    {
        $callElementId = '20260617-abf59f4c-361b-46c5-b22c-f3c7c9aeee27';

        // Morpheus CX does not support audio streaming — expect a 404 plain-text response
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get(route('admin.communications.zoom.recordings.media', [
                'recordingId' => $callElementId,
                'source' => 'phone',
                'action' => 'play',
            ]), ['Accept' => 'audio/*,*/*'])
            ->assertNotFound();
    }

    public function test_recording_media_resolves_call_history_id_via_call_logs_endpoint(): void
    {
        // Morpheus CX does not support audio streaming — expect a 404 plain-text response
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get(route('admin.communications.zoom.recordings.media', [
                'recordingId' => 'hist_99',
                'source' => 'phone',
                'action' => 'play',
            ]), ['Accept' => 'audio/*,*/*'])
            ->assertNotFound();
    }

    public function test_recording_media_rejects_invalid_cached_call_history_download_url(): void
    {
        // Morpheus CX does not support audio streaming — expect a 404
        $callHistoryId = '20260617-abf59f4c-361b-46c5-b22c-f3c7c9aeee27';

        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get(route('admin.communications.zoom.recordings.media', [
                'recordingId' => $callHistoryId,
                'source' => 'phone',
                'action' => 'play',
            ]), ['Accept' => 'audio/*,*/*'])
            ->assertNotFound();
    }

    public function test_recording_media_returns_plain_text_error_for_audio_fetch(): void
    {
        // Morpheus CX stub always throws — should return plain-text 404 with 'Recording not found'
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get(route('admin.communications.zoom.recordings.media', [
                'recordingId' => 'missing_recording',
                'source' => 'phone',
                'action' => 'play',
            ]), [
                'Accept' => 'audio/*,*/*',
            ])
            ->assertNotFound()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSee('Recording not found', false);
    }

    public function test_recording_media_streams_when_cached(): void
    {
        // Morpheus CX stub always throws — cached or not, expect 404
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get(route('admin.communications.zoom.recordings.media', [
                'recordingId' => 'rec_test',
                'source' => 'phone',
                'action' => 'play',
            ]), ['Accept' => 'audio/*,*/*'])
            ->assertNotFound();
    }

    public function test_admin_can_open_recordings_voicemails_sms_and_team_tabs(): void
    {
        $this->mockZoomServices();

        $admin = $this->makeAdmin();

        foreach (['recordings', 'voicemail', 'sms', 'chat', 'team'] as $channel) {
            $this->actingAs($admin)
                ->get(route('admin.communications.index', ['channel' => $channel]))
                ->assertOk()
                ->assertSee('Communications');
        }

        $this->actingAs($admin)
            ->get(route('admin.communications.index', ['panel' => 'dialer']))
            ->assertOk()
            ->assertSee('Phone dialer');
    }

    public function test_admin_can_open_dialer_with_prefill_number(): void
    {
        $this->mockZoomServices();

        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get(route('admin.communications.index', ['panel' => 'dialer', 'number' => '+15559876543']))
            ->assertOk()
            ->assertSee('Phone dialer')
            ->assertSee('Call with Morpheus CX')
            ->assertSee('+15559876543', false);
    }

    public function test_inbox_contact_detail_renders_without_error(): void
    {
        $this->mockZoomServices();

        $contacts = Mockery::mock(ZoomContactService::class);
        $contacts->shouldReceive('buildIndexPayload')->andReturn([
            'contacts' => [[
                'contact_key' => 'user:u1',
                'name' => 'Mark Barrette',
                'phone' => '+12098526079',
                'email' => 'mark@example.com',
                'tag' => 'user',
                'last_activity_at' => now()->toIso8601String(),
                'last_activity_type' => 'call',
            ]],
            'call_logs' => [],
            'error' => null,
        ]);
        $contacts->shouldReceive('buildShowPayload')->andReturn([
            'contact' => [
                'contact_key' => 'user:u1',
                'name' => 'Mark Barrette',
                'phone' => '+12098526079',
                'email' => 'mark@example.com',
                'tag' => 'user',
                'last_activity_at' => now()->toIso8601String(),
                'last_activity_type' => 'call',
            ],
            'timeline' => [[
                'type' => 'call',
                'label' => 'Call inbound',
                'at' => now()->toIso8601String(),
                'detail' => 'connected · 30s',
                'direction' => 'inbound',
                'from' => 'Caller',
                'to' => 'Mark',
            ]],
            'stats' => [
                'call_count' => 1,
                'sms_count' => 0,
                'voicemail_count' => 0,
                'activity_count' => 1,
                'last_activity_at' => now()->toIso8601String(),
            ],
            'sms_session' => null,
            'error' => null,
        ]);
        $this->app->instance(ZoomContactService::class, $contacts);

        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get(route('admin.communications.index', ['channel' => 'inbox', 'contact' => 'user:u1']))
            ->assertOk()
            ->assertSee('Mark Barrette')
            ->assertSee('Activity timeline');
    }

    public function test_inbox_still_loads_when_phone_users_fail(): void
    {
        $zoom = Mockery::mock(ZoomApiService::class);
        $zoom->shouldReceive('isConfigured')->andReturn(true);
        $zoom->shouldReceive('connectionStatus')->andReturn([
            'connected' => true,
            'message' => 'Connected to Morpheus CX API.',
            'expires_at' => null,
        ]);
        $this->mockZoomConnectionDiagnostics($zoom);
        $zoom->shouldReceive('accountId')->andReturn('acct_test');
        $zoom->shouldReceive('clientId')->andReturn('client_test');
        $zoom->shouldReceive('maskedSecret')->andReturn('••••••••1234');
        $zoom->shouldReceive('webhookSecret')->andReturn(null);
        $zoom->shouldReceive('requiredScopes')->andReturn([]);
        $zoom->shouldReceive('humanizeError')->andReturnUsing(fn (string $message) => $message);

        $contacts = Mockery::mock(ZoomContactService::class);
        $contacts->shouldReceive('buildIndexPayload')->andReturn([
            'contacts' => [[
                'contact_key' => 'user:u1',
                'name' => 'Mark Barrette',
                'phone' => null,
                'email' => 'mark@example.com',
                'tag' => 'user',
                'last_activity_at' => now()->toIso8601String(),
                'last_activity_type' => 'login',
            ]],
            'call_logs' => [],
            'error' => null,
        ]);
        $contacts->shouldReceive('buildShowPayload')->andReturn([
            'contact' => null,
            'timeline' => [],
            'stats' => [],
            'error' => null,
        ]);

        $data = Mockery::mock(CommunicationsDataService::class);
        $data->shouldReceive('phoneUsers')->andReturn([
            'users' => [],
            'warning' => 'Morpheus CX: no extensions configured.',
        ]);
        $data->shouldReceive('recentDialNumbers')->andReturn([]);
        $data->shouldReceive('callLogs')->andReturn(['logs' => [], 'next_page_token' => null]);
        $data->shouldReceive('callStatsFromLogs')->andReturn([
            'total' => 0,
            'inbound' => 0,
            'outbound' => 0,
            'recorded' => 0,
            'missed' => 0,
            'total_duration' => 0,
        ]);
        $data->shouldReceive('voiceMails')->andReturn(['voice_mails' => [], 'warning' => null]);
        $data->shouldReceive('smsSessions')->andReturn(['sessions' => [], 'warning' => null]);

        $this->app->instance(ZoomApiService::class, $zoom);
        $this->app->instance(ZoomContactService::class, $contacts);
        $this->app->instance(CommunicationsDataService::class, $data);

        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get(route('admin.communications.index', ['channel' => 'inbox']))
            ->assertOk()
            ->assertSee('Mark Barrette')
            ->assertSee('Morpheus CX: no extensions', false);
    }

    public function test_sms_compose_panel_loads(): void
    {
        $this->mockZoomServices();

        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get(route('admin.communications.index', ['channel' => 'sms', 'panel' => 'compose_sms']))
            ->assertOk()
            ->assertSee('New SMS message');
    }

    public function test_admin_can_refresh_communications_cache(): void
    {
        $this->mockZoomServices();
        $data = Mockery::mock(CommunicationsDataService::class);
        $data->shouldReceive('bustCache')->once();
        $this->app->instance(CommunicationsDataService::class, $data);

        $zoom = Mockery::mock(ZoomApiService::class);
        $zoom->shouldReceive('isConfigured')->andReturn(true);
        $zoom->shouldReceive('connectionStatus')->andReturn([
            'connected' => true,
            'message' => 'Connected',
            'expires_at' => null,
        ]);
        $this->mockZoomConnectionDiagnostics($zoom);
        $zoom->shouldReceive('accountId')->andReturn('acct');
        $zoom->shouldReceive('clientId')->andReturn('client');
        $zoom->shouldReceive('maskedSecret')->andReturn('••••');
        $zoom->shouldReceive('webhookSecret')->andReturn(null);
        $zoom->shouldReceive('requiredScopes')->andReturn([]);
        $zoom->shouldReceive('clearAccessTokenCache')->once();
        $this->app->instance(ZoomApiService::class, $zoom);

        $contacts = Mockery::mock(ZoomContactService::class);
        $this->app->instance(ZoomContactService::class, $contacts);

        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->post(route('admin.communications.zoom.refresh'))
            ->assertRedirect(route('admin.communications.index', ['channel' => 'inbox', 'panel' => 'settings']))
            ->assertSessionHas('success');
    }

    public function test_calls_tab_supports_missed_filter(): void
    {
        $this->mockZoomServices();
        $data = Mockery::mock(CommunicationsDataService::class);
        $data->shouldReceive('phoneUsers')->andReturn(['users' => [], 'warning' => null]);
        $data->shouldReceive('recentDialNumbers')->andReturn([]);
        $data->shouldReceive('voiceMails')->andReturn(['voice_mails' => [], 'warning' => null]);
        $data->shouldReceive('smsSessions')->andReturn(['sessions' => [], 'warning' => null]);
        $data->shouldReceive('callLogs')->andReturn([
            'logs' => [
                [
                    'direction' => 'inbound',
                    'from' => 'Caller',
                    'to' => 'Agent',
                    'from_phone' => '+15551111111',
                    'to_phone' => '+15552222222',
                    'start_time' => now()->toIso8601String(),
                    'result' => 'no answer',
                    'duration' => 0,
                    'recording' => '—',
                ],
                [
                    'direction' => 'outbound',
                    'from' => 'Agent',
                    'to' => 'Prospect',
                    'from_phone' => '+15552222222',
                    'to_phone' => '+15553333333',
                    'start_time' => now()->subHour()->toIso8601String(),
                    'result' => 'connected',
                    'duration' => 30,
                    'recording' => '—',
                ],
            ],
            'next_page_token' => null,
        ]);
        $data->shouldReceive('callStatsFromLogs')->andReturn([
            'total' => 1,
            'inbound' => 1,
            'outbound' => 0,
            'recorded' => 0,
            'missed' => 1,
            'total_duration' => 0,
        ]);
        $this->app->instance(CommunicationsDataService::class, $data);

        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get(route('admin.communications.index', ['channel' => 'calls', 'filter' => 'missed']))
            ->assertOk()
            ->assertSee('no answer', false)
            ->assertDontSee('connected', false);
    }

    public function test_voicemail_media_streams_when_cached(): void
    {
        // Morpheus CX stub always throws — expect 404
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get(route('admin.communications.zoom.voicemails.media', [
                'fileId' => 'file_test',
                'action' => 'play',
            ]))
            ->assertNotFound();
    }

    protected function makeAdmin(): User
    {
        $admin = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Acme', 'admin_id' => $admin->id]);
        $workspace->users()->attach($admin->id, ['role' => 'admin', 'status' => 'active', 'joined_at' => now()]);
        $admin->update(['current_workspace_id' => $workspace->id]);

        return $admin;
    }

    protected function mockZoomConnectionDiagnostics($zoom): void
    {
        $zoom->shouldReceive('connectionDiagnostics')->andReturn([
            'phone_available' => true,
            'messages' => [],
        ]);
    }

    protected function mockZoomServices(): void
    {
        $zoom = Mockery::mock(ZoomApiService::class);
        $zoom->shouldReceive('isConfigured')->andReturn(true);
        $zoom->shouldReceive('connectionStatus')->andReturn([
            'connected' => true,
            'message' => 'Connected to Morpheus CX API.',
            'expires_at' => null,
        ]);
        $this->mockZoomConnectionDiagnostics($zoom);
        $zoom->shouldReceive('accountId')->andReturn('acct_test');
        $zoom->shouldReceive('clientId')->andReturn('client_test');
        $zoom->shouldReceive('maskedSecret')->andReturn('••••••••1234');
        $zoom->shouldReceive('webhookSecret')->andReturn(null);
        $zoom->shouldReceive('requiredScopes')->andReturn([]);

        $contacts = Mockery::mock(ZoomContactService::class);
        $contacts->shouldReceive('buildIndexPayload')->andReturn([
            'contacts' => [[
                'contact_key' => 'user:u1',
                'name' => 'Mark Barrette',
                'phone' => '+12098526079',
                'email' => 'mark@example.com',
                'tag' => 'user',
                'last_activity_at' => now()->toIso8601String(),
                'last_activity_type' => 'call',
            ]],
            'call_logs' => [],
            'error' => null,
        ]);
        $contacts->shouldReceive('buildShowPayload')->andReturn([
            'contact' => null,
            'timeline' => [],
            'stats' => [],
            'error' => null,
        ]);

        $this->app->instance(ZoomApiService::class, $zoom);
        $this->app->instance(ZoomContactService::class, $contacts);

        $this->mockHubDataService();
    }

    protected function mockHubDataService(): void
    {
        $data = Mockery::mock(CommunicationsDataService::class);
        $data->shouldReceive('users')->andReturn([]);
        $data->shouldReceive('callLogs')->andReturn(['logs' => [], 'next_page_token' => null]);
        $data->shouldReceive('callStats')->andReturn([
            'total' => 0,
            'inbound' => 0,
            'outbound' => 0,
            'recorded' => 0,
            'missed' => 0,
            'total_duration' => 0,
        ]);
        $data->shouldReceive('recordings')->andReturn([
            'recordings' => [],
            'next_page_token' => null,
            'warnings' => [],
        ]);
        $data->shouldReceive('voiceMails')->andReturn([
            'voice_mails' => [],
            'next_page_token' => null,
            'warning' => null,
        ]);
        $data->shouldReceive('smsSessions')->andReturn([
            'sessions' => [],
            'next_page_token' => null,
            'warning' => null,
        ]);
        $data->shouldReceive('smsMessages')->andReturn([
            'messages' => [],
            'next_page_token' => null,
        ]);
        $data->shouldReceive('teamChatChannels')->andReturn([
            'channels' => [],
            'next_page_token' => null,
            'warning' => null,
        ]);
        $data->shouldReceive('teamChatMessages')->andReturn([
            'messages' => [],
            'next_page_token' => null,
        ]);
        $data->shouldReceive('callQueues')->andReturn([
            'queues' => [],
            'next_page_token' => null,
            'warning' => null,
        ]);
        $data->shouldReceive('phoneUsers')->andReturn([
            'users' => [[
                'id' => 'pu1',
                'name' => 'Agent One',
                'email' => 'agent@example.com',
                'extension_number' => '101',
                'phone_numbers' => ['+15551230001'],
                'default_caller_id' => '+15551230001',
                'status' => 'active',
            ]],
            'next_page_token' => null,
            'warning' => null,
        ]);
        $data->shouldReceive('recentDialNumbers')->andReturn(['+15559876543']);
        $data->shouldReceive('callStatsFromLogs')->andReturn([
            'total' => 0,
            'inbound' => 0,
            'outbound' => 0,
            'recorded' => 0,
            'missed' => 0,
            'total_duration' => 0,
        ]);

        $this->app->instance(CommunicationsDataService::class, $data);
    }
}
