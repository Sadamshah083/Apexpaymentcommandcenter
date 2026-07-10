<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Communications\ZoomContactService;
use App\Services\Communications\CommunicationsDataService;
use App\Services\Communications\CommunicationsCallHistoryService;
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
            'integrations.morpheus.sip_host' => 'apexone.morpheus.cx',
            'integrations.morpheus.sip_params' => 'user=phone',
            'integrations.morpheus.dial_method' => 'api',
        ]);
    }

    public function test_admin_can_open_phone_hub(): void
    {
        $this->mockZoomServices();

        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get(route('admin.communications.index'))
            ->assertOk()
            ->assertSee('Phone')
            ->assertSee('Call logs')
            ->assertSee('Call with Morpheus CX')
            ->assertDontSee('super_secret_value_1234');
    }

    public function test_legacy_communications_query_params_redirect_to_clean_url(): void
    {
        $this->mockZoomServices();

        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get(route('admin.communications.index', ['channel' => 'inbox', 'panel' => 'dialer']))
            ->assertRedirect(route('admin.communications.index'));
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
            ->assertSee('Phone');
    }

    public function test_zoom_settings_panel_is_no_longer_exposed_in_phone_hub(): void
    {
        $this->mockZoomServices();

        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get(route('admin.communications.index', ['panel' => 'settings']))
            ->assertRedirect(route('admin.communications.index'));
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

    public function test_admin_can_originate_outbound_call_with_sip_launch(): void
    {
        config(['integrations.morpheus.dial_method' => 'sip']);

        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->post(route('admin.communications.morpheus.calls.originate'), [
                'from_extension' => '1001',
                'destination' => '+15551234567',
                'fallback' => 'sip',
            ])
            ->assertOk()
            ->assertSee('Opening your softphone')
            ->assertSee('sip:15551234567@apexone.morpheus.cx;user=phone', false);
    }

    public function test_admin_can_originate_outbound_call_with_api_then_sip_fallback(): void
    {
        config(['integrations.morpheus.dial_method' => 'api']);

        Http::fake([
            'https://apexone.morpheus.cx/api/v1/call-control/calls/originate' => Http::response(['error' => 'not found'], 404),
            'https://apexone.morpheus.cx/api/v1/call-control/calls' => Http::response(['error' => 'method not allowed'], 405),
            'https://apexone.morpheus.cx/api/v1/call-control/dial' => Http::response(['error' => 'not found'], 404),
            'https://apexone.morpheus.cx/api/v1/call-control/originate' => Http::response(['error' => 'not found'], 404),
        ]);

        $zoom = Mockery::mock(ZoomApiService::class);
        $zoom->shouldReceive('isConfigured')->andReturn(true);
        $zoom->shouldReceive('humanizeError')->zeroOrMoreTimes()->andReturnUsing(fn ($m) => $m);
        $zoom->shouldReceive('normalizeOriginateCallerId')->zeroOrMoreTimes()->andReturnArg(0);
        $zoom->shouldReceive('defaultOutboundCampaignId')->zeroOrMoreTimes()->andReturn('camp_123');
        $zoom->shouldReceive('clearExtensionForOutboundDial')->zeroOrMoreTimes();
        $zoom->shouldReceive('getCall')->zeroOrMoreTimes()->andReturn([]);
        $zoom->shouldReceive('outboundCallingProfile')->zeroOrMoreTimes()->andReturn([]);
        $zoom->shouldReceive('listExtensions')->zeroOrMoreTimes()->andReturn(['extensions' => []]);
        $zoom->shouldReceive('listUsers')->zeroOrMoreTimes()->andReturn(['users' => []]);
        $this->app->instance(ZoomApiService::class, $zoom);

        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->post(route('admin.communications.morpheus.calls.originate'), [
                'from_extension' => '1001',
                'destination' => '+15551234567',
                'fallback' => 'sip',
            ])
            ->assertOk()
            ->assertSee('Opening your softphone')
            ->assertSee('sip:15551234567@apexone.morpheus.cx;user=phone', false);
    }

    public function test_admin_auto_dial_attempts_api_before_sip_fallback_when_endpoint_hint_looks_offline(): void
    {
        config(['integrations.morpheus.dial_method' => 'auto']);

        $zoom = Mockery::mock(ZoomApiService::class);
        $zoom->shouldReceive('isConfigured')->andReturn(true);
        $zoom->shouldReceive('listExtensions')->andReturn([
            'extensions' => [[
                'id' => 'ext_1001',
                'extension_num' => '1001',
                'caller_id_name' => 'Agent One',
                'caller_id_num' => '+15551230001',
                'outbound_cid_num' => '+15551230001',
                'user_id' => 'user_1001',
            ]],
        ]);
        $zoom->shouldReceive('listUsers')->andReturn([
            'users' => [[
                'id' => 'user_1001',
                'email' => 'agent@example.com',
            ]],
        ]);
        $zoom->shouldReceive('originateCall')
            ->once()
            ->with('1001', '+15551234567', Mockery::type('array'))
            ->andReturn([
                'ok' => true,
                'call_uuid' => 'uuid-1',
                'outcome' => 'ringing',
            ]);
        $zoom->shouldReceive('humanizeError')->zeroOrMoreTimes()->andReturnUsing(fn ($m) => $m);
        $zoom->shouldReceive('normalizeOriginateCallerId')->zeroOrMoreTimes()->andReturnArg(0);
        $zoom->shouldReceive('defaultOutboundCampaignId')->zeroOrMoreTimes()->andReturn('camp_123');
        $zoom->shouldReceive('clearExtensionForOutboundDial')->zeroOrMoreTimes();
        $zoom->shouldReceive('getCall')->zeroOrMoreTimes()->andReturn([]);
        $zoom->shouldReceive('outboundCallingProfile')->zeroOrMoreTimes()->andReturn([]);

        $this->app->instance(ZoomApiService::class, $zoom);

        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->from(route('admin.communications.index', ['panel' => 'dialer']))
            ->post(route('admin.communications.morpheus.calls.originate'), [
                'from_extension' => '1001',
                'destination' => '+15551234567',
                'fallback' => 'sip',
            ])
            ->assertRedirect(route('admin.communications.index'))
            ->assertSessionHas('success', 'Outbound call ringing. Answer your extension or softphone when it rings.');
    }

    public function test_legacy_hub_channels_redirect_to_phone_hub(): void
    {
        $this->mockZoomServices();

        $admin = $this->makeAdmin();

        foreach (['queues', 'conferences', 'leads', 'campaigns', 'lists', 'extensions', 'team'] as $channel) {
            $this->actingAs($admin)
                ->get(route('admin.communications.index', ['channel' => $channel]))
                ->assertRedirect(route('admin.communications.index'));
        }

        $this->actingAs($admin)
            ->get(route('admin.communications.index'))
            ->assertOk()
            ->assertSee('Call with Morpheus CX');
    }

    public function test_admin_can_open_dialer_with_prefill_number(): void
    {
        $this->mockZoomServices();

        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get(route('admin.communications.index', ['number' => '+15559876543']))
            ->assertOk()
            ->assertSee('Call with Morpheus CX')
            ->assertSee('+15559876543', false);
    }

    public function test_admin_can_transfer_active_call_via_morpheus_api(): void
    {
        Http::fake([
            'https://apexone.morpheus.cx/api/v1/call-control/calls/*' => Http::response(['ok' => true, 'action' => 'transfer'], 200),
        ]);

        $admin = $this->makeAdmin();
        $uuid = '550e8400-e29b-41d4-a716-446655440000';

        $this->actingAs($admin)
            ->from(route('admin.communications.index', ['channel' => 'calls']))
            ->post(route('admin.communications.morpheus.calls.transfer', ['uuid' => $uuid]), [
                'destination' => '8003',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        Http::assertSent(function ($request) use ($uuid) {
            return $request->method() === 'POST'
                && str_contains($request->url(), "/calls/{$uuid}/transfer")
                && $request['destination'] === '8003'
                && $request->hasHeader('X-API-Key', 'ck_test_super_secret_value_1234');
        });
    }

    public function test_legacy_contact_links_redirect_to_phone_hub(): void
    {
        $this->mockZoomServices();

        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get(route('admin.communications.index', ['channel' => 'inbox', 'contact' => 'user:u1']))
            ->assertRedirect(route('admin.communications.index'));
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
        $zoom->shouldReceive('outboundCallingProfile')->zeroOrMoreTimes()->andReturn([]);
        $zoom->shouldReceive('normalizeOriginateCallerId')->zeroOrMoreTimes()->andReturnArg(0);
        $zoom->shouldReceive('defaultOutboundCampaignId')->zeroOrMoreTimes()->andReturn('camp_123');
        $zoom->shouldReceive('clearExtensionForOutboundDial')->zeroOrMoreTimes();
        $zoom->shouldReceive('getCall')->zeroOrMoreTimes()->andReturn([]);

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
            ->get(route('admin.communications.index'))
            ->assertOk()
            ->assertSee('Call logs')
            ->assertSee('Morpheus CX: no extensions', false);
    }

    public function test_sms_compose_panel_is_no_longer_exposed(): void
    {
        $this->mockZoomServices();

        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get(route('admin.communications.index', ['channel' => 'sms', 'panel' => 'compose_sms']))
            ->assertRedirect(route('admin.communications.index'));
    }

    public function test_dialer_call_logs_api_returns_paginated_json(): void
    {
        $zoom = Mockery::mock(ZoomApiService::class);
        $zoom->shouldReceive('isConfigured')->andReturn(false);
        $this->app->instance(ZoomApiService::class, $zoom);

        $admin = $this->makeAdmin();

        for ($i = 0; $i < 25; $i++) {
            \App\Models\CommunicationCallLog::create([
                'workspace_id' => $admin->current_workspace_id,
                'user_id' => $admin->id,
                'direction' => 'outbound',
                'from_extension' => '1020',
                'to_phone' => '+1555000'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'status' => 'completed',
                'started_at' => now()->subMinutes($i),
            ]);
        }

        $this->actingAs($admin)
            ->getJson(route('admin.communications.dialer.call-logs', ['offset' => 0, 'per_page' => 10]))
            ->assertOk()
            ->assertJsonStructure(['logs', 'next_offset', 'has_more'])
            ->assertJsonPath('has_more', true)
            ->assertJsonCount(10, 'logs');
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
        $zoom->shouldReceive('outboundCallingProfile')->zeroOrMoreTimes()->andReturn([]);
        $zoom->shouldReceive('humanizeError')->zeroOrMoreTimes()->andReturnUsing(fn ($m) => $m);
        $zoom->shouldReceive('normalizeOriginateCallerId')->zeroOrMoreTimes()->andReturnArg(0);
        $zoom->shouldReceive('defaultOutboundCampaignId')->zeroOrMoreTimes()->andReturn('camp_123');
        $zoom->shouldReceive('clearExtensionForOutboundDial')->zeroOrMoreTimes();
        $zoom->shouldReceive('getCall')->zeroOrMoreTimes()->andReturn([]);
        $this->app->instance(ZoomApiService::class, $zoom);

        $contacts = Mockery::mock(ZoomContactService::class);
        $this->app->instance(ZoomContactService::class, $contacts);

        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->post(route('admin.communications.zoom.refresh'))
            ->assertRedirect(route('admin.communications.index'))
            ->assertSessionHas('success');
    }

    public function test_calls_tab_supports_missed_filter(): void
    {
        $this->mockZoomServices();

        // Seed the local call history service so the sidebar sees the calls
        $callHistory = Mockery::mock(CommunicationsCallHistoryService::class);
        $callHistory->shouldReceive('listForHub')->andReturn([
            [
                'id' => 'log-1',
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
                'id' => 'log-2',
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
        ]);
        $this->app->instance(CommunicationsCallHistoryService::class, $callHistory);

        $data = Mockery::mock(CommunicationsDataService::class);
        $data->shouldReceive('phoneUsers')->andReturn(['users' => [], 'warning' => null]);
        $data->shouldReceive('recentDialNumbers')->andReturn([]);
        $data->shouldReceive('voiceMails')->andReturn(['voice_mails' => [], 'warning' => null]);
        $data->shouldReceive('smsSessions')->andReturn(['sessions' => [], 'warning' => null]);
        $data->shouldReceive('callLogs')->andReturn(['logs' => [], 'next_page_token' => null]);
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
            ->assertRedirect(route('admin.communications.index'));
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
        $zoom->shouldReceive('humanizeError')->andReturnUsing(fn ($m) => $m);
        $zoom->shouldReceive('outboundCallingProfile')->zeroOrMoreTimes()->andReturn([]);
        $zoom->shouldReceive('normalizeOriginateCallerId')->zeroOrMoreTimes()->andReturnArg(0);
        $zoom->shouldReceive('defaultOutboundCampaignId')->zeroOrMoreTimes()->andReturn('camp_123');
        $zoom->shouldReceive('clearExtensionForOutboundDial')->zeroOrMoreTimes();
        $zoom->shouldReceive('getCall')->zeroOrMoreTimes()->andReturn([]);

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

    public function test_portal_agent_sees_limited_communications_channels(): void
    {
        $this->mockZoomServices();

        $agent = $this->makePortalAgent('appointment_setter');

        $this->actingAs($agent)
            ->get(route('portal.communications.index'))
            ->assertOk()
            ->assertSee('Phone')
            ->assertSee('Call with Morpheus CX')
            ->assertDontSee('Phone agents')
            ->assertDontSee('>Queues<', false);
    }

    public function test_portal_agent_cannot_open_admin_configuration_channels(): void
    {
        $this->mockZoomServices();

        $agent = $this->makePortalAgent('closer');

        $this->actingAs($agent)
            ->get(route('portal.communications.index', ['channel' => 'queues']))
            ->assertRedirect(route('portal.communications.index'));
    }

    public function test_portal_agent_cannot_refresh_communications_cache(): void
    {
        $this->mockZoomServices();

        $agent = $this->makePortalAgent('appointment_setter');

        $this->actingAs($agent)
            ->post(route('portal.communications.zoom.refresh'))
            ->assertForbidden();
    }

    public function test_portal_agent_cannot_create_morpheus_queue(): void
    {
        $this->mockZoomServices();

        $agent = $this->makePortalAgent('appointment_setter_team_lead');

        $this->actingAs($agent)
            ->post(route('portal.communications.morpheus.queues.store'), ['name' => 'Sales'])
            ->assertForbidden();
    }

    public function test_portal_agent_can_originate_call(): void
    {
        Http::fake([
            'apexone.morpheus.cx/*' => Http::response(['ok' => true, 'call_uuid' => 'uuid-1'], 200),
        ]);

        $zoom = Mockery::mock(ZoomApiService::class);
        $zoom->shouldReceive('isConfigured')->andReturn(true);
        $zoom->shouldReceive('originateCall')
            ->once()
            ->with('1001', Mockery::type('string'), Mockery::type('array'))
            ->andReturn(['ok' => true, 'call_uuid' => 'uuid-1']);
        $zoom->shouldReceive('humanizeError')->andReturnUsing(fn ($m) => $m);
        $zoom->shouldReceive('normalizeOriginateCallerId')->zeroOrMoreTimes()->andReturnArg(0);
        $zoom->shouldReceive('defaultOutboundCampaignId')->zeroOrMoreTimes()->andReturn('camp_123');
        $zoom->shouldReceive('clearExtensionForOutboundDial')->zeroOrMoreTimes();
        $zoom->shouldReceive('getCall')->zeroOrMoreTimes()->andReturn([]);
        $zoom->shouldReceive('listExtensions')->andReturn(['extensions' => []]);
        $zoom->shouldReceive('listUsers')->andReturn(['users' => []]);
        $this->app->instance(ZoomApiService::class, $zoom);

        $agent = $this->makePortalAgent('appointment_setter');

        $this->actingAs($agent)
            ->post(route('portal.communications.morpheus.calls.originate'), [
                'destination' => '5551234567',
                'from_extension' => '1001',
            ])
            ->assertRedirect();
    }

    public function test_portal_agent_cannot_originate_from_another_extension(): void
    {
        config(['integrations.morpheus.dial_method' => 'api']);

        $zoom = Mockery::mock(ZoomApiService::class);
        $zoom->shouldReceive('isConfigured')->andReturn(true);
        $zoom->shouldReceive('originateCall')->never();
        $zoom->shouldReceive('listExtensions')->andReturn(['extensions' => []]);
        $zoom->shouldReceive('listUsers')->andReturn(['users' => []]);
        $this->app->instance(ZoomApiService::class, $zoom);

        $agent = $this->makePortalAgent('appointment_setter');

        $this->actingAs($agent)
            ->post(route('portal.communications.morpheus.calls.originate'), [
                'destination' => '5551234567',
                'from_extension' => '1002',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_manager_sees_operational_channels_not_phone_agents(): void
    {
        $this->mockZoomServices();

        $manager = $this->makeManager();

        $this->actingAs($manager)
            ->get(route('admin.communications.index', ['channel' => 'queues']))
            ->assertRedirect(route('admin.communications.index'));
    }

    public function test_team_lead_sees_team_channel_in_portal(): void
    {
        $this->mockZoomServices();

        $lead = $this->makePortalAgent('appointment_setter_team_lead');

        $this->actingAs($lead)
            ->get(route('portal.communications.index', ['channel' => 'team']))
            ->assertRedirect(route('portal.communications.index'));
    }

    public function test_manager_cannot_create_morpheus_queue(): void
    {
        $this->mockZoomServices();

        $manager = $this->makeManager();

        $this->actingAs($manager)
            ->post(route('admin.communications.morpheus.queues.store'), ['name' => 'Sales'])
            ->assertForbidden();
    }

    protected function makeManager(): User
    {
        $owner = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Acme', 'admin_id' => $owner->id]);
        $workspace->users()->attach($owner->id, ['role' => 'admin', 'status' => 'active', 'joined_at' => now()]);

        $manager = User::factory()->create([
            'current_workspace_id' => $workspace->id,
        ]);
        $workspace->users()->attach($manager->id, [
            'role' => 'manager',
            'status' => 'active',
            'joined_at' => now(),
            'module_permissions' => json_encode(['dashboard', 'communications']),
        ]);

        return $manager;
    }

    protected function makePortalAgent(string $role): User
    {
        $owner = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Acme', 'admin_id' => $owner->id]);
        $workspace->users()->attach($owner->id, ['role' => 'admin', 'status' => 'active', 'joined_at' => now()]);

        $agent = User::factory()->create([
            'current_workspace_id' => $workspace->id,
        ]);
        $workspace->users()->attach($agent->id, [
            'role' => $role,
            'status' => 'active',
            'joined_at' => now(),
            'morpheus_extension_num' => '1001',
        ]);

        return $agent;
    }
}
