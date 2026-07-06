<?php

namespace Tests\Unit\Services;

use App\Services\Integrations\ZoomApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZoomApiServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'integrations.morpheus.api_key' => 'ck_test_super_secret_value_1234',
            'integrations.morpheus.host' => 'apexone.morpheus.cx',
            'integrations.morpheus.default_campaign_id' => 'campaign-default-123',
            'integrations.communications.http_timeout_seconds' => 8,
            'integrations.zoom.account_id' => 'acct_test',
            'integrations.zoom.client_id' => 'client_test',
            'integrations.zoom.client_secret' => 'secret_test',
        ]);

        Cache::forget('zoom.s2s.access_token');
    }

    public function test_originate_call_includes_default_campaign_id_when_not_explicitly_provided(): void
    {
        config([
            'integrations.morpheus.host' => 'apexone.morpheus.cx',
            'integrations.morpheus.api_key' => 'test-key',
            'integrations.morpheus.default_campaign_id' => '6c753496-2efd-4783-aa85-eb6ec73bc512',
        ]);

        Http::fake([
            'https://apexone.morpheus.cx/api/v1/call-control/click-to-call' => Http::response([
                'ok' => true,
                'call_uuid' => 'uuid-1',
            ], 200),
            'https://apexone.morpheus.cx/api/v1/call-control/calls/uuid-1' => Http::response([
                'live' => true,
                'status' => 'ringing',
                'call_uuid' => 'uuid-1',
            ], 200),
        ]);

        $service = new ZoomApiService;
        $result = $service->originateCall('1001', '+15551234567', [
            'caller_id_number' => '+12016444668',
            'caller_id_name' => 'Agent One',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame('1001', $result['from']);
        $this->assertSame('15551234567', $result['to']);
        $this->assertTrue($result['internal_from']);
        $this->assertSame('6c753496-2efd-4783-aa85-eb6ec73bc512', $result['campaign_id']);
        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && $request->url() === 'https://apexone.morpheus.cx/api/v1/call-control/click-to-call'
                && $request['extension'] === '1001'
                && $request['destination'] === '15551234567'
                && $request['campaign_id'] === '6c753496-2efd-4783-aa85-eb6ec73bc512'
                && $request['caller_id_number'] === '12016444668'
                && $request['caller_id_name'] === 'Agent One';
        });
    }

    public function test_resolve_call_snapshot_falls_back_to_cdr_when_live_call_missing(): void
    {
        config([
            'integrations.morpheus.host' => 'apexone.morpheus.cx',
            'integrations.morpheus.api_key' => 'test-key',
        ]);

        Http::fake([
            'https://apexone.morpheus.cx/api/v1/call-control/calls/uuid-cdr-only' => Http::response([], 404),
            'https://apexone.morpheus.cx/api/v1/call-control/calls' => Http::response(['calls' => []], 200),
            'https://apexone.morpheus.cx/api/v1/call-control/cdr*' => Http::response([
                'cdr' => [[
                    'call_uuid' => 'uuid-cdr-only',
                    'billsec' => 12,
                    'call_outcome' => 'connected',
                    'hangup_cause' => 'NORMAL_CLEARING',
                    'destination_number' => '12722001232',
                ]],
            ], 200),
        ]);

        $service = new ZoomApiService;
        $snapshot = $service->getCall('uuid-cdr-only');

        $this->assertNotNull($snapshot);
        $this->assertSame('uuid-cdr-only', $snapshot['uuid']);
        $this->assertSame(12, $snapshot['billsec']);
    }

    public function test_format_originate_response_normalizes_from_and_to_fields(): void
    {
        $service = new ZoomApiService;

        $formatted = $service->formatOriginateResponse(
            [
                'ok' => true,
                'action' => 'originate',
                'call_uuid' => '14d44c38-e321-48b1-9ae0-1a437eceea98',
                'outcome' => 'ringing',
                'attempted' => ['POST /click-to-call'],
            ],
            '1020',
            '+12722001232',
            ['campaign_id' => '6c753496-2efd-4783-aa85-eb6ec73bc512'],
        );

        $this->assertTrue($formatted['ok']);
        $this->assertSame('originate', $formatted['action']);
        $this->assertSame('14d44c38-e321-48b1-9ae0-1a437eceea98', $formatted['call_uuid']);
        $this->assertSame('6c753496-2efd-4783-aa85-eb6ec73bc512', $formatted['campaign_id']);
        $this->assertSame('1020', $formatted['from']);
        $this->assertSame('12722001232', $formatted['to']);
        $this->assertTrue($formatted['internal_from']);
        $this->assertSame('ringing', $formatted['outcome']);
    }

    public function test_normalize_originate_caller_id_strips_plus_prefix(): void
    {
        $service = new ZoomApiService;

        $this->assertSame('12016444668', $service->normalizeOriginateCallerId('+12016444668'));
        $this->assertNull($service->normalizeOriginateCallerId(null));
    }

    public function test_normalize_originate_destination_strips_carrier_prefix_and_plus(): void
    {
        $service = new ZoomApiService;

        $this->assertSame('12722001232', $service->normalizeOriginateDestination('482983#+12722001232'));
        $this->assertSame('12722001232', $service->normalizeOriginateDestination('+12722001232'));
        $this->assertSame('1020', $service->normalizeOriginateDestination('1020'));
    }

    public function test_list_cdr_uses_agent_extension_for_outbound_from_display(): void
    {
        config([
            'integrations.morpheus.host' => 'apexone.morpheus.cx',
            'integrations.morpheus.api_key' => 'test-key',
            'integrations.communications.default_outbound_did' => '+13133851223',
        ]);

        Http::fake([
            'https://apexone.morpheus.cx/api/v1/call-control/cdr*' => Http::response([
                'cdr' => [[
                    'call_uuid' => 'uuid-webrtc-leg',
                    'direction' => 'outbound',
                    'caller_id_name' => 'Outbound Call',
                    'caller_id_number' => 'u46e0ned',
                    'destination_number' => '12722001232',
                    'agent_extension' => '1020',
                    'billsec' => 33,
                    'call_outcome' => 'short',
                    'hangup_cause' => 'NORMAL_CLEARING',
                ]],
            ], 200),
        ]);

        $logs = (new ZoomApiService)->listCdr()['logs'];

        $this->assertCount(1, $logs);
        $this->assertSame('ext 1020 · +13133851223', $logs[0]['from']);
        $this->assertSame('+13133851223', $logs[0]['from_phone']);
        $this->assertSame('+12722001232', $logs[0]['to_phone']);
    }

    public function test_list_cdr_maps_sip_username_to_outbound_did_without_agent_extension(): void
    {
        config([
            'integrations.morpheus.host' => 'apexone.morpheus.cx',
            'integrations.morpheus.api_key' => 'test-key',
            'integrations.communications.default_outbound_did' => '+13133851223',
        ]);

        Http::fake([
            'https://apexone.morpheus.cx/api/v1/call-control/cdr*' => Http::response([
                'cdr' => [[
                    'call_uuid' => 'uuid-sip-caller',
                    'direction' => 'outbound',
                    'caller_id_name' => 'Outbound Call',
                    'caller_id_number' => 'b8s8ruho',
                    'destination_number' => '12722001232',
                    'agent_extension' => null,
                    'billsec' => 3,
                    'call_outcome' => 'short',
                ]],
            ], 200),
        ]);

        $logs = (new ZoomApiService)->listCdr()['logs'];

        $this->assertCount(1, $logs);
        $this->assertSame('+13133851223', $logs[0]['from_phone']);
        $this->assertSame('+13133851223', $logs[0]['from']);
        $this->assertSame('+12722001232', $logs[0]['to_phone']);
    }

    public function test_list_cdr_filters_internal_webrtc_bridge_legs(): void
    {
        config([
            'integrations.morpheus.host' => 'apexone.morpheus.cx',
            'integrations.morpheus.api_key' => 'test-key',
        ]);

        Http::fake([
            'https://apexone.morpheus.cx/api/v1/call-control/cdr*' => Http::response([
                'cdr' => [
                    [
                        'call_uuid' => 'uuid-internal',
                        'direction' => 'outbound',
                        'caller_id_number' => '13133851223',
                        'destination_number' => 'vv0aou9q',
                        'call_outcome' => 'no_answer',
                    ],
                    [
                        'call_uuid' => 'uuid-pstn',
                        'direction' => 'outbound',
                        'caller_id_number' => '8niimj2m',
                        'destination_number' => '12722001232',
                        'agent_extension' => '1020',
                        'billsec' => 8,
                        'call_outcome' => 'short',
                    ],
                ],
            ], 200),
        ]);

        $logs = (new ZoomApiService)->listCdr()['logs'];

        $this->assertCount(1, $logs);
        $this->assertSame('uuid-pstn', $logs[0]['id']);
        $this->assertSame('+12722001232', $logs[0]['to_phone']);
    }

    public function test_resolve_call_snapshot_prefers_pstn_cdr_leg_over_agent_ring_leg(): void
    {
        config([
            'integrations.morpheus.host' => 'apexone.morpheus.cx',
            'integrations.morpheus.api_key' => 'test-key',
        ]);

        Http::fake([
            'https://apexone.morpheus.cx/api/v1/call-control/calls/shared-uuid' => Http::response([], 404),
            'https://apexone.morpheus.cx/api/v1/call-control/calls' => Http::response(['calls' => []], 200),
            'https://apexone.morpheus.cx/api/v1/call-control/cdr*' => Http::response([
                'cdr' => [
                    [
                        'call_uuid' => 'shared-uuid',
                        'caller_id_number' => '13133851223',
                        'destination_number' => 'n6qiqk02',
                        'billsec' => 0,
                        'hangup_cause' => 'NO_ANSWER',
                        'call_outcome' => 'no_answer',
                    ],
                    [
                        'call_uuid' => 'shared-uuid',
                        'caller_id_number' => 'n6qiqk02',
                        'destination_number' => '12722001232',
                        'agent_extension' => '1020',
                        'billsec' => 12,
                        'hangup_cause' => 'NORMAL_CLEARING',
                        'call_outcome' => 'connected',
                    ],
                ],
            ], 200),
        ]);

        $snapshot = (new ZoomApiService)->getCall('shared-uuid');

        $this->assertNotNull($snapshot);
        $this->assertSame('12722001232', $snapshot['destination_number']);
        $this->assertSame(12, $snapshot['billsec']);
    }

    public function test_originate_call_strips_carrier_prefix_from_destination(): void
    {
        config([
            'integrations.morpheus.host' => 'apexone.morpheus.cx',
            'integrations.morpheus.api_key' => 'test-key',
            'integrations.morpheus.default_campaign_id' => 'campaign-default-123',
        ]);

        Http::fake([
            'https://apexone.morpheus.cx/api/v1/call-control/click-to-call' => Http::response([
                'ok' => true,
                'call_uuid' => 'uuid-prefix',
            ], 200),
            'https://apexone.morpheus.cx/api/v1/call-control/calls/uuid-prefix' => Http::response([
                'live' => true,
                'status' => 'ringing',
            ], 200),
        ]);

        $service = new ZoomApiService;
        $result = $service->originateCall('1020', '482983#12722001232');

        $this->assertTrue($result['ok']);
        $this->assertSame('12722001232', $result['to']);
        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && $request['extension'] === '1020'
                && $request['destination'] === '12722001232';
        });
    }

    public function test_connection_status_succeeds_with_valid_token(): void
    {
        Http::fake([
            'zoom.us/oauth/token' => Http::response([
                'access_token' => 'token_abc',
                'expires_in' => 3600,
            ], 200),
        ]);

        $status = app(ZoomApiService::class)->connectionStatus();

        $this->assertTrue($status['connected']);
        $this->assertSame('Connected to Zoom API.', $status['message']);
    }

    public function test_list_call_logs_returns_normalized_rows(): void
    {
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'token_abc', 'expires_in' => 3600], 200),
            'api.zoom.us/v2/phone/call_history*' => Http::response([
                'call_history' => [[
                    'call_history_uuid' => 'hist_1',
                    'direction' => 'outbound',
                    'caller_name' => 'Mark',
                    'caller_did_number' => '+12098526079',
                    'callee_did_number' => '+15715231943',
                    'start_time' => '2026-06-16T20:40:07Z',
                    'duration' => 240,
                    'call_result' => 'connected',
                    'recording_status' => 'non_recorded',
                ]],
            ], 200),
            'api.zoom.us/v2/phone/recordings*' => Http::response(['recordings' => []], 200),
        ]);

        $logs = app(ZoomApiService::class)->normalizedCallLogs();

        $this->assertCount(1, $logs);
        $this->assertSame('outbound', $logs[0]['direction']);
        $this->assertSame('+12098526079', $logs[0]['from_phone']);
        $this->assertSame('connected', $logs[0]['result']);
    }

    public function test_humanize_error_extracts_missing_scopes(): void
    {
        $service = app(ZoomApiService::class);

        $message = 'Zoom API error: {"code":104,"message":"Invalid access token, does not contain scopes:[phone:read:list_call_logs:admin]."}';

        $human = $service->humanizeError($message);

        $this->assertStringContainsString('phone:read:list_call_logs:admin', $human);
        $this->assertStringContainsString('zoom:clear-token', $human);
    }

    public function test_list_recordings_uses_call_history_page_when_phone_api_empty(): void
    {
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'token_abc', 'expires_in' => 3600], 200),
            'api.zoom.us/v2/phone/recordings*' => Http::response([
                'code' => 104,
                'message' => 'Invalid access token, does not contain scopes:[phone:read:list_call_recordings:admin].',
            ], 403),
            'api.zoom.us/v2/phone/call_history*' => Http::response([
                'call_history' => [[
                    'call_history_uuid' => 'hist_rec_1',
                    'caller_name' => 'Damon',
                    'callee_did_number' => '+18505577522',
                    'start_time' => '2026-06-16T21:56:10Z',
                    'duration' => 7,
                    'recording_status' => 'recorded',
                ]],
            ], 200),
        ]);

        Cache::flush();
        $payload = app(ZoomApiService::class)->listRecordings();

        $this->assertCount(1, $payload['recordings']);
        $this->assertNotEmpty($payload['warnings']);
    }

    public function test_list_recordings_falls_back_to_user_endpoint_on_account_scope_error(): void
    {
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'token_abc', 'expires_in' => 3600], 200),
            'api.zoom.us/v2/phone/recordings*' => Http::response(['recordings' => [[
                'id' => 'phone_1',
                'caller_name' => 'Host',
                'callee_name' => 'Guest',
                'date_time' => '2026-06-10T15:00:00Z',
                'duration' => 60,
                'download_url' => 'https://zoom.us/rec/download/phone_1',
            ]]], 200),
        ]);

        Cache::flush();
        $payload = app(ZoomApiService::class)->listRecordings();

        $this->assertCount(1, $payload['recordings']);
        $this->assertSame('Phone call · Host → Guest', $payload['recordings'][0]['topic']);
    }

    public function test_normalized_call_logs_link_phone_recordings(): void
    {
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'token_abc', 'expires_in' => 3600], 200),
            'api.zoom.us/v2/phone/call_history*' => Http::response([
                'call_history' => [[
                    'call_history_uuid' => 'hist_99',
                    'call_id' => 'call_99',
                    'direction' => 'inbound',
                    'caller_name' => 'Prospect',
                    'caller_did_number' => '+15715231943',
                    'callee_name' => 'Agent',
                    'callee_did_number' => '+12098526079',
                    'start_time' => '2026-06-16T20:40:07Z',
                    'duration' => 90,
                    'call_result' => 'connected',
                    'recording_status' => 'recorded',
                ]],
            ], 200),
        ]);

        Cache::flush();
        $logs = app(ZoomApiService::class)->normalizedCallLogs();

        $this->assertSame('hist_99', $logs[0]['recording_id']);
        $this->assertTrue($logs[0]['has_recording_media']);
    }

    public function test_list_voice_mails_returns_normalized_rows(): void
    {
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'token_abc', 'expires_in' => 3600], 200),
            'api.zoom.us/v2/phone/voice_mails*' => Http::response([
                'voice_mails' => [[
                    'id' => 'vm_1',
                    'file_id' => 'file_1',
                    'caller_name' => 'Prospect',
                    'caller_number' => '+15715231943',
                    'callee_name' => 'Agent',
                    'date_time' => '2026-06-16T20:40:07Z',
                    'duration' => 42,
                    'status' => 'unread',
                    'download_url' => 'https://zoom.us/v2/phone/voice_mails/download/file_1',
                ]],
            ], 200),
        ]);

        $payload = app(ZoomApiService::class)->listVoiceMails();

        $this->assertCount(1, $payload['voice_mails']);
        $this->assertSame('Prospect', $payload['voice_mails'][0]['caller']);
        $this->assertTrue($payload['voice_mails'][0]['has_media']);
    }

    public function test_list_sms_sessions_returns_normalized_rows(): void
    {
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'token_abc', 'expires_in' => 3600], 200),
            'api.zoom.us/v2/phone/sms/sessions*' => Http::response([
                'sms_sessions' => [[
                    'session_id' => 'sess_1',
                    'session_type' => 'user',
                    'last_access_time' => '2026-06-16T20:40:07Z',
                    'participants' => [[
                        'display_name' => 'Agent',
                        'phone_number' => '+12098526079',
                        'is_session_owner' => true,
                    ], [
                        'display_name' => 'Lead',
                        'phone_number' => '+15715231943',
                        'is_session_owner' => false,
                    ]],
                ]],
            ], 200),
        ]);

        $payload = app(ZoomApiService::class)->listSmsSessions();

        $this->assertCount(1, $payload['sessions']);
        $this->assertSame('sess_1', $payload['sessions'][0]['session_id']);
        $this->assertSame('Lead', $payload['sessions'][0]['label']);
    }

    public function test_list_voice_mails_falls_back_to_user_endpoint_when_account_empty(): void
    {
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'token_abc', 'expires_in' => 3600], 200),
            'api.zoom.us/v2/phone/voice_mails*' => Http::response(['voice_mails' => []], 200),
            'api.zoom.us/v2/users*' => Http::response([
                'users' => [['id' => 'user_1', 'email' => 'agent@example.com']],
            ], 200),
            'api.zoom.us/v2/phone/users/user_1/voice_mails*' => Http::response([
                'voice_mails' => [[
                    'id' => 'vm_user_1',
                    'file_id' => 'file_user_1',
                    'caller_name' => 'Caller',
                    'owner' => ['name' => 'Agent'],
                    'date_time' => '2026-06-16T20:40:07Z',
                    'duration' => 30,
                    'status' => 'unread',
                ]],
            ], 200),
        ]);

        $payload = app(ZoomApiService::class)->listVoiceMails();

        $this->assertCount(1, $payload['voice_mails']);
        $this->assertSame('Agent', $payload['voice_mails'][0]['callee']);
    }

    public function test_list_sms_sessions_falls_back_to_user_endpoint_when_account_empty(): void
    {
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'token_abc', 'expires_in' => 3600], 200),
            'api.zoom.us/v2/phone/sms/sessions*' => Http::response(['sms_sessions' => []], 200),
            'api.zoom.us/v2/users*' => Http::response([
                'users' => [['id' => 'user_1', 'email' => 'agent@example.com']],
            ], 200),
            'api.zoom.us/v2/phone/users/user_1/sms/sessions*' => Http::response([
                'sms_sessions' => [[
                    'session_id' => 'sess_user_1',
                    'session_type' => 'user',
                    'last_access_time' => '2026-06-16T20:40:07Z',
                    'participants' => [[
                        'display_name' => 'Agent',
                        'phone_number' => '+12098526079',
                        'is_session_owner' => true,
                    ], [
                        'display_name' => 'Lead',
                        'phone_number' => '+15715231943',
                        'is_session_owner' => false,
                    ]],
                ]],
            ], 200),
        ]);

        $payload = app(ZoomApiService::class)->listSmsSessions();

        $this->assertCount(1, $payload['sessions']);
        $this->assertSame('sess_user_1', $payload['sessions'][0]['session_id']);
    }

    public function test_get_sms_session_messages_falls_back_to_user_endpoint(): void
    {
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'token_abc', 'expires_in' => 3600], 200),
            'api.zoom.us/v2/phone/sms/sessions/sess_1*' => Http::response(['sms_histories' => []], 200),
            'api.zoom.us/v2/users*' => Http::response([
                'users' => [['id' => 'user_1', 'email' => 'agent@example.com']],
            ], 200),
            'api.zoom.us/v2/phone/users/user_1/sms/sessions/sess_1*' => Http::response([
                'sms_histories' => [[
                    'message_id' => 'msg_1',
                    'message' => 'Hello there',
                    'date_time' => '2026-06-16T20:40:07Z',
                    'direction' => 'inbound',
                    'delivery_status' => 'delivered',
                ]],
            ], 200),
        ]);

        $payload = app(ZoomApiService::class)->getSmsSessionMessages('sess_1');

        $this->assertCount(1, $payload['messages']);
        $this->assertSame('Hello there', $payload['messages'][0]['message']);
    }

    public function test_stream_recording_resolves_call_element_id(): void
    {
        $callElementId = '20260617-abf59f4c-361b-46c5-b22c-f3c7c9aeee27';

        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'token_abc', 'expires_in' => 3600], 200),
            'api.zoom.us/v2/phone/call_logs/'.$callElementId.'/recordings*' => Http::response(['recordings' => []], 200),
            'api.zoom.us/v2/phone/call_element/'.$callElementId.'*' => Http::response([
                'recording_id' => 'rec_from_element',
                'download_url' => 'https://zoom.us/v2/phone/recording/download/rec_from_element',
            ], 200),
            'api.zoom.us/v2/phone/call_history/'.$callElementId.'*' => Http::response([], 404),
            'api.zoom.us/v2/phone/call_history_detail/'.$callElementId.'*' => Http::response([], 404),
            'api.zoom.us/v2/phone/recordings*' => Http::response(['recordings' => []], 200),
            'api.zoom.us/v2/phone/recording/download/rec_from_element*' => Http::response('FAKEAUDIO', 200, [
                'Content-Type' => 'audio/mpeg',
            ]),
        ]);

        $response = app(ZoomApiService::class)->streamRecording('phone', $callElementId, false);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('FAKEAUDIO', $response->getContent());
    }

    public function test_stream_recording_returns_buffered_audio_from_cache(): void
    {
        Cache::put('zoom.recording.rec_test', [
            'source' => 'phone',
            'download_url' => 'https://zoom.us/v2/phone/recording/download/rec_test',
            'play_url' => null,
            'content_type' => 'audio/mpeg',
            'file_id' => 'rec_test',
        ], now()->addHour());

        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'token_abc', 'expires_in' => 3600], 200),
            'api.zoom.us/v2/phone/recording/download/rec_test*' => Http::response('FAKEAUDIO', 200, [
                'Content-Type' => 'audio/mpeg',
            ]),
        ]);

        $response = app(ZoomApiService::class)->streamRecording('phone', 'rec_test', false);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('FAKEAUDIO', $response->getContent());
    }

    public function test_list_team_chat_channels_returns_normalized_rows(): void
    {
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'token_abc', 'expires_in' => 3600], 200),
            'api.zoom.us/v2/users*' => Http::response([
                'users' => [[
                    'id' => 'user_1',
                    'email' => 'agent@example.com',
                    'first_name' => 'Agent',
                    'last_name' => 'One',
                ]],
            ], 200),
            'api.zoom.us/v2/chat/users/user_1/channels*' => Http::response([
                'channels' => [[
                    'id' => 'ch_1',
                    'name' => 'Sales Team',
                    'type' => 2,
                    'members_count' => 8,
                    'last_message_sent_time' => '2026-06-16T20:40:07Z',
                ]],
            ], 200),
        ]);

        $payload = app(ZoomApiService::class)->listTeamChatChannels();

        $this->assertCount(1, $payload['channels']);
        $this->assertSame('user_1:ch_1', $payload['channels'][0]['thread_key']);
        $this->assertSame('Sales Team', $payload['channels'][0]['label']);
    }

    public function test_get_team_chat_messages_returns_normalized_rows(): void
    {
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'token_abc', 'expires_in' => 3600], 200),
            'api.zoom.us/v2/chat/users/user_1/messages*' => Http::response([
                'messages' => [[
                    'id' => 'msg_1',
                    'message' => 'Hello team',
                    'date_time' => '2026-06-16T20:40:07Z',
                    'sender' => ['id' => 'user_2', 'display_name' => 'Lead'],
                ]],
            ], 200),
        ]);

        $payload = app(ZoomApiService::class)->getTeamChatMessages('user_1', [
            'to_channel' => 'ch_1',
        ]);

        $this->assertCount(1, $payload['messages']);
        $this->assertSame('Hello team', $payload['messages'][0]['message']);
        $this->assertSame('inbound', $payload['messages'][0]['direction']);
    }

    public function test_list_call_queues_returns_normalized_rows(): void
    {
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'token_abc', 'expires_in' => 3600], 200),
            'api.zoom.us/v2/phone/call_queues*' => Http::response([
                'call_queues' => [[
                    'id' => 'cq_1',
                    'name' => 'Sales Queue',
                    'extension_number' => 8001,
                    'status' => 'active',
                    'site' => ['name' => 'Main Site'],
                    'phone_numbers' => [['number' => '+12058945717']],
                ]],
            ], 200),
        ]);

        $payload = app(ZoomApiService::class)->listCallQueues();

        $this->assertCount(1, $payload['queues']);
        $this->assertSame('Sales Queue', $payload['queues'][0]['name']);
        $this->assertSame('+12058945717', $payload['queues'][0]['phone_numbers'][0]);
    }

    public function test_list_phone_users_returns_normalized_rows(): void
    {
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'token_abc', 'expires_in' => 3600], 200),
            'api.zoom.us/v2/phone/users*' => Http::response([
                'users' => [[
                    'id' => 'pu_1',
                    'email' => 'agent@example.com',
                    'name' => 'Agent One',
                    'extension_number' => '101',
                    'phone_numbers' => [['number' => '+15551230001']],
                    'status' => 'active',
                ]],
            ], 200),
        ]);

        $payload = app(ZoomApiService::class)->listPhoneUsers();

        $this->assertCount(1, $payload['users']);
        $this->assertSame('Agent One', $payload['users'][0]['name']);
        $this->assertSame('+15551230001', $payload['users'][0]['default_caller_id']);
    }

    public function test_list_phone_users_handles_phone_not_enabled(): void
    {
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'token_abc', 'expires_in' => 3600], 200),
            'api.zoom.us/v2/phone/users*' => Http::response([
                'code' => 124,
                'message' => 'Zoom Phone has not been enabled for this account.',
            ], 400),
        ]);

        $payload = app(ZoomApiService::class)->listPhoneUsers();

        $this->assertSame([], $payload['users']);
        $this->assertNotNull($payload['warning']);
        $this->assertStringContainsString('Zoom Phone', $payload['warning']);
    }

    public function test_list_call_logs_handles_phone_not_enabled(): void
    {
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'token_abc', 'expires_in' => 3600], 200),
            'api.zoom.us/v2/phone/call_history*' => Http::response([
                'code' => 2031,
                'message' => 'Zoom Phone has not been enabled for this account.',
            ], 400),
            'api.zoom.us/v2/phone/call_logs*' => Http::response([
                'code' => 2031,
                'message' => 'Zoom Phone has not been enabled for this account.',
            ], 400),
        ]);

        $payload = app(ZoomApiService::class)->listCallLogs();

        $this->assertSame([], $payload['call_logs']);
        $this->assertNotNull($payload['warning']);
    }

    public function test_request_retries_once_when_token_missing_new_scopes(): void
    {
        Http::fake([
            'zoom.us/oauth/token' => Http::sequence()
                ->push(['access_token' => 'stale_token', 'expires_in' => 3600], 200)
                ->push(['access_token' => 'fresh_token', 'expires_in' => 3600], 200),
            'api.zoom.us/v2/users*' => Http::sequence()
                ->push(['code' => 4711, 'message' => 'Invalid access token, does not contain scopes:[user:read:list_users:admin].'], 401)
                ->push(['users' => [['id' => 'u1', 'first_name' => 'A', 'last_name' => 'B', 'email' => 'a@example.com']], 'next_page_token' => null], 200),
        ]);

        $payload = app(ZoomApiService::class)->listUsers();

        $this->assertCount(1, $payload['users']);
        Http::assertSentCount(4);
    }

    public function test_send_sms_message_posts_to_zoom(): void
    {
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'token_abc', 'expires_in' => 3600], 200),
            'api.zoom.us/v2/phone/sms/messages' => Http::response([
                'session_id' => 'sms_sess_new',
                'message_id' => 'msg_new',
                'date_time' => '2026-06-16T20:40:07Z',
            ], 201),
        ]);

        $result = app(ZoomApiService::class)->sendSmsMessage([
            'sender' => [
                'phone_number' => '+15551230001',
                'id' => 'pu_1',
                'user_id' => 'pu_1',
            ],
            'to_members' => [['phone_number' => '+15559876543']],
            'message' => 'Hello there',
            'session_id' => 'sms_sess_new',
        ]);

        $this->assertSame('sms_sess_new', $result['session_id']);
    }
}
