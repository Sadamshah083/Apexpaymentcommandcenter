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
            'integrations.zoom.account_id' => 'acct_test',
            'integrations.zoom.client_id' => 'client_test',
            'integrations.zoom.client_secret' => 'secret_test',
        ]);

        Cache::forget('zoom.s2s.access_token');
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
