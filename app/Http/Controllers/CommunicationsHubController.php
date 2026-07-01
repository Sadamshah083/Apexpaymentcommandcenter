<?php



namespace App\Http\Controllers;



use App\Services\Communications\CommunicationsDataService;

use App\Services\Communications\ZoomContactService;

use App\Services\Communications\CommunicationsInboxService;

use App\Services\Communications\ZoomClickToCallService;

use App\Services\Integrations\ZoomApiService;

use Illuminate\Http\Request;

use Illuminate\Support\Facades\Response;



class CommunicationsHubController extends Controller

{

    public function __construct(

        protected ZoomContactService $contacts,

        protected ZoomApiService $zoom,

        protected CommunicationsDataService $data,

        protected ZoomClickToCallService $clickToCall,

        protected CommunicationsInboxService $inbox,

    ) {}



    public function index(Request $request)
    {
        return $this->inboxView($request);
    }



    public function showContact(string $contactKey, Request $request)

    {

        return redirect()->route($this->routePrefix().'communications.index', array_merge(

            $this->filters($request),

            ['channel' => 'inbox', 'contact' => $contactKey]

        ));

    }



    public function exportLogs(Request $request)

    {

        $filters = $this->filters($request);



        try {

            $logs = $this->data->callLogs($filters, 5)['logs'];

        } catch (\Throwable $e) {

            return back()->with('error', $this->zoom->humanizeError($e->getMessage()));

        }



        $filename = 'zoom-call-logs-'.now()->format('Y-m-d').'.csv';



        $callback = function () use ($logs) {

            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['direction', 'from', 'to', 'start_time', 'result', 'duration', 'recording']);



            foreach ($logs as $log) {

                fputcsv($handle, [

                    $log['direction'],

                    $log['from'],

                    $log['to'],

                    $log['start_time'],

                    $log['result'],

                    $log['duration'],

                    $log['recording'] ?? '—',

                ]);

            }



            fclose($handle);

        };



        return Response::stream($callback, 200, [

            'Content-Type' => 'text/csv',

            'Content-Disposition' => "attachment; filename=\"{$filename}\"",

        ]);

    }



    public function recordingMedia(Request $request, string $recordingId)

    {

        $source = (string) $request->query('source', 'phone');

        $download = $request->query('action', 'play') === 'download';

        $callReferenceId = $request->query('call_ref');



        try {

            return $this->zoom->streamRecording(
                $source,
                $recordingId,
                $download,
                is_string($callReferenceId) && $callReferenceId !== '' ? $callReferenceId : null
            );

        } catch (\Throwable $e) {

            return $this->recordingMediaErrorResponse($request, $e);

        }

    }



    protected function recordingMediaErrorResponse(Request $request, \Throwable $e)

    {

        $message = $this->zoom->humanizeError($e->getMessage());



        if ($this->wantsPlainRecordingMediaError($request)) {

            return response($message, 404, ['Content-Type' => 'text/plain; charset=UTF-8']);

        }



        abort(404, $message);

    }



    protected function wantsPlainRecordingMediaError(Request $request): bool

    {

        if ($request->ajax() || $request->expectsJson()) {

            return true;

        }



        $accept = (string) $request->header('Accept', '');



        return str_contains($accept, 'audio') || str_contains($accept, '*/*');

    }



    public function voicemailMedia(Request $request, string $fileId)

    {

        $download = $request->query('action', 'play') === 'download';



        try {

            return $this->zoom->streamVoicemail($fileId, $download);

        } catch (\Throwable $e) {

            abort(404, $this->zoom->humanizeError($e->getMessage()));

        }

    }



    protected function inboxView(Request $request)

    {

        return view('communications.inbox.index', $this->inbox->build($request, $this->routePrefix()));

    }



    protected function contactsView(Request $request)

    {

        $filters = $this->filters($request);

        $payload = $this->contacts->buildIndexPayload($filters);

        $selectedKey = $request->get('contact');

        $selected = null;

        $timeline = [];

        $stats = [];

        $detailError = null;

        $smsSession = null;



        if ($selectedKey) {

            $show = $this->contacts->buildShowPayload($selectedKey, $filters);

            $selected = $show['contact'];

            $timeline = $show['timeline'];

            $stats = $show['stats'];

            $detailError = $show['error'];

            $smsSession = $show['sms_session'] ?? null;

        }



        return view('communications.contacts.index', [

            'contacts' => $payload['contacts'],

            'error' => $payload['error'] ?? $detailError,

            'mode' => 'contacts',

            'routePrefix' => $this->routePrefix(),

            'filters' => $filters,

            'selectedContact' => $selected,

            'timeline' => $timeline,

            'stats' => $stats,

            'smsSession' => $smsSession ?? null,

            'selectedKey' => $selectedKey,

        ]);

    }



    protected function callsView(Request $request)

    {

        $filters = $this->filters($request);

        $error = null;

        $callLogs = [];

        $nextPageToken = null;

        $warnings = [];

        $stats = [];



        if ($this->zoom->isConfigured()) {

            try {

                $payload = $this->data->callLogs($filters, 1);

                $callLogs = $payload['logs'];



                if (($filters['direction'] ?? '') !== '') {

                    $callLogs = array_values(array_filter(

                        $callLogs,

                        fn ($log) => ($log['direction'] ?? '') === $filters['direction']

                    ));

                }



                if (($filters['filter'] ?? '') === 'recorded') {

                    $callLogs = array_values(array_filter(

                        $callLogs,

                        fn ($log) => ($log['recording'] ?? '') === 'Yes' || ! empty($log['has_recording_media'])

                    ));

                }



                if (($filters['filter'] ?? '') === 'missed') {

                    $callLogs = array_values(array_filter(

                        $callLogs,

                        fn ($log) => $this->isMissedCall($log)

                    ));

                }



                $nextPageToken = $payload['next_page_token'];

                $stats = $this->data->callStatsFromLogs($callLogs);

            } catch (\Throwable $e) {

                $error = $this->zoom->humanizeError($e->getMessage());

            }

        } else {

            $error = 'Zoom is not configured.';

        }



        return view('communications.calls.index', [

            'mode' => 'calls',

            'routePrefix' => $this->routePrefix(),

            'filters' => $filters,

            'callLogs' => $callLogs,

            'nextPageToken' => $nextPageToken,

            'warnings' => $warnings,

            'error' => $error,

            'stats' => $stats,

        ]);

    }



    protected function dialerView(Request $request)

    {

        $filters = $this->filters($request);

        $error = null;

        $warning = null;

        $phoneUsers = [];

        $recentNumbers = [];

        $prefillNumber = $request->get('number');



        if ($this->zoom->isConfigured()) {

            try {

                $payload = $this->data->phoneUsers($filters);

                $phoneUsers = $payload['users'];

                $warning = $payload['warning'];

                $recentNumbers = $this->data->recentDialNumbers($filters);

            } catch (\Throwable $e) {

                $error = $this->zoom->humanizeError($e->getMessage());

            }

        } else {

            $error = 'Zoom is not configured.';

        }



        return view('communications.dialer.index', [

            'mode' => 'dialer',

            'routePrefix' => $this->routePrefix(),

            'filters' => $filters,

            'phoneUsers' => $phoneUsers,

            'recentNumbers' => $recentNumbers,

            'prefillNumber' => is_string($prefillNumber) ? $prefillNumber : null,

            'defaultCallerId' => config('integrations.communications.default_caller_id'),

            'error' => $error,

            'warning' => $warning,

            'clickToCall' => $this->clickToCall,

        ]);

    }



    protected function recordingsView(Request $request)

    {

        $filters = $this->filters($request);

        $error = null;

        $recordings = [];

        $nextPageToken = null;

        $warnings = [];



        if ($this->zoom->isConfigured()) {

            try {

                $payload = $this->data->recordings($filters);

                $recordings = $payload['recordings'];

                $nextPageToken = $payload['next_page_token'];

                $warnings = $payload['warnings'] ?? [];

            } catch (\Throwable $e) {

                $error = $this->zoom->humanizeError($e->getMessage());

            }

        } else {

            $error = 'Zoom is not configured.';

        }



        return view('communications.recordings.index', [

            'mode' => 'recordings',

            'routePrefix' => $this->routePrefix(),

            'filters' => $filters,

            'recordings' => $recordings,

            'nextPageToken' => $nextPageToken,

            'warnings' => $warnings,

            'error' => $error,

        ]);

    }



    protected function voicemailsView(Request $request)

    {

        $filters = $this->filters($request);

        $error = null;

        $voiceMails = [];

        $nextPageToken = null;

        $warning = null;



        if ($this->zoom->isConfigured()) {

            try {

                $payload = $this->data->voiceMails($filters);

                $voiceMails = $payload['voice_mails'];

                $nextPageToken = $payload['next_page_token'];

                $warning = $payload['warning'];

            } catch (\Throwable $e) {

                $error = $this->zoom->humanizeError($e->getMessage());

            }

        } else {

            $error = 'Zoom is not configured.';

        }



        return view('communications.voicemails.index', [

            'mode' => 'voicemails',

            'routePrefix' => $this->routePrefix(),

            'filters' => $filters,

            'voiceMails' => $voiceMails,

            'nextPageToken' => $nextPageToken,

            'warning' => $warning,

            'error' => $error,

        ]);

    }



    protected function smsView(Request $request)

    {

        $filters = $this->filters($request);

        $error = null;

        $sessions = [];

        $nextPageToken = null;

        $warning = null;

        $messages = [];

        $selectedSession = null;

        $messagesNextPageToken = null;

        $phoneUsers = [];



        if ($this->zoom->isConfigured()) {

            try {

                $payload = $this->data->smsSessions($filters);

                $sessions = $payload['sessions'];

                $nextPageToken = $payload['next_page_token'];

                $warning = $payload['warning'];



                $sessionId = $request->get('session');

                if ($sessionId) {

                    $selectedSession = collect($sessions)->firstWhere('session_id', $sessionId);

                    if (! $selectedSession) {
                        $selectedSession = [
                            'session_id' => $sessionId,
                            'label' => 'SMS conversation',
                            'session_type' => 'user',
                            'owner_phone' => null,
                            'other_phone' => null,
                            'last_access_time' => null,
                        ];
                    }

                    $messagePayload = $this->data->smsMessages($sessionId, array_merge($filters, [
                        'page_token' => $request->get('msg_page_token'),
                    ]));

                    $messages = collect($messagePayload['messages'])->sortBy('date_time')->values()->all();

                    $messagesNextPageToken = $messagePayload['next_page_token'];

                }



                $phoneUsers = $this->data->phoneUsers($filters)['users'];

            } catch (\Throwable $e) {

                $error = $this->zoom->humanizeError($e->getMessage());

            }

        } else {

            $error = 'Zoom is not configured.';

        }



        return view('communications.sms.index', [

            'mode' => 'sms',

            'routePrefix' => $this->routePrefix(),

            'filters' => $filters,

            'sessions' => $sessions,

            'nextPageToken' => $nextPageToken,

            'warning' => $warning,

            'error' => $error,

            'selectedSessionId' => $request->get('session'),

            'selectedSession' => $selectedSession,

            'messages' => $messages,

            'messagesNextPageToken' => $messagesNextPageToken,

            'phoneUsers' => $phoneUsers,

        ]);

    }



    public function refreshCache(Request $request)

    {

        $this->data->bustCache();

        $this->zoom->clearAccessTokenCache();

        \Illuminate\Support\Facades\Cache::forget('zoom.connection.diagnostics');



        return redirect()->route($this->routePrefix().'communications.index', ['channel' => 'inbox', 'panel' => 'settings'])

            ->with('success', 'Communications cache refreshed. Fresh Zoom data will load on your next tab visit.');

    }



    public function sendSms(Request $request)

    {

        $validated = $request->validate([

            'sender_user_id' => ['nullable', 'string'],

            'sender_phone' => ['nullable', 'string'],

            'sender_line' => ['nullable', 'string'],

            'to_phone' => ['required', 'string'],

            'message' => ['required', 'string', 'max:1600'],

            'session_id' => ['nullable', 'string'],

        ]);



        if (filled($validated['sender_line'] ?? null)) {

            [$senderUserId, $senderPhone] = array_pad(explode('|', $validated['sender_line'], 2), 2, null);

            $validated['sender_user_id'] = $validated['sender_user_id'] ?: $senderUserId;

            $validated['sender_phone'] = $validated['sender_phone'] ?: $senderPhone;

        }



        if (! filled($validated['sender_user_id'] ?? null) || ! filled($validated['sender_phone'] ?? null)) {

            return back()->withInput()->with('error', 'Choose a sender phone line before sending SMS.');

        }



        $clickToCall = $this->clickToCall;



        try {

            $payload = [

                'sender' => [

                    'phone_number' => $clickToCall->normalizePhone($validated['sender_phone']),

                    'id' => $validated['sender_user_id'],

                    'user_id' => $validated['sender_user_id'],

                ],

                'to_members' => [[

                    'phone_number' => $clickToCall->normalizePhone($validated['to_phone']),

                ]],

                'message' => $validated['message'],

            ];



            if (filled($validated['session_id'] ?? null)) {

                $payload['session_id'] = $validated['session_id'];

            }



            $result = $this->zoom->sendSmsMessage($payload);

            $this->data->bustCache();



            $redirectParams = ['channel' => 'sms'];

            if (filled($result['session_id'] ?? $validated['session_id'] ?? null)) {

                $redirectParams['session'] = $result['session_id'] ?? $validated['session_id'];

            }



            return redirect()->route($this->routePrefix().'communications.index', $redirectParams)

                ->with('success', 'SMS sent successfully.');

        } catch (\Throwable $e) {

            return back()->withInput()->with('error', $this->zoom->humanizeError($e->getMessage()));

        }

    }

    public function transferCall(Request $request, string $uuid)
    {
        $validated = $request->validate([
            'destination' => ['required', 'string', 'max:64'],
        ]);

        try {
            $this->zoom->transferCall($uuid, $validated['destination']);
            return redirect()->route($this->routePrefix().'communications.index', ['mode' => 'calls'])
                ->with('success', 'Call transferred successfully.');
        } catch (\Throwable $e) {
            return back()->with('error', $this->zoom->humanizeError($e->getMessage()));
        }
    }

    public function sendChat(Request $request)
    {
        $validated = $request->validate([
            'owner_user_id' => ['required', 'string'],
            'chat_channel' => ['nullable', 'string'],
            'chat_contact' => ['nullable', 'string'],
            'message' => ['required', 'string', 'max:4096'],
        ]);

        if (! filled($validated['chat_channel'] ?? null) && ! filled($validated['chat_contact'] ?? null)) {
            return back()->withInput()->with('error', 'Select a chat channel or contact before sending.');
        }

        try {
            $payload = ['message' => $validated['message']];

            if (filled($validated['chat_channel'] ?? null)) {
                $payload['to_channel'] = $validated['chat_channel'];
            } else {
                $payload['to_contact'] = $validated['chat_contact'];
            }

            $this->zoom->sendTeamChatMessage($validated['owner_user_id'], $payload);
            $this->data->bustCache();

            return redirect()->route($this->routePrefix().'communications.index', array_filter([
                'channel' => 'chat',
                'chat_owner' => $validated['owner_user_id'],
                'chat_channel' => $validated['chat_channel'] ?? null,
                'chat_contact' => $validated['chat_contact'] ?? null,
            ]))->with('success', 'Chat message sent.');
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $this->zoom->humanizeError($e->getMessage()));
        }
    }



    protected function chatView(Request $request)

    {

        $filters = $this->filters($request);

        $error = null;

        $channels = [];

        $nextPageToken = null;

        $warning = null;

        $messages = [];

        $selectedThread = null;

        $messagesNextPageToken = null;

        $ownerUserId = $request->get('chat_owner');

        $channelId = $request->get('chat_channel');

        $contactEmail = $request->get('chat_contact');



        if ($this->zoom->isConfigured()) {

            try {

                $payload = $this->data->teamChatChannels($filters);

                $channels = $payload['channels'];

                $nextPageToken = $payload['next_page_token'];

                $warning = $payload['warning'];



                if ($ownerUserId && ($channelId || $contactEmail)) {

                    $threadKey = $channelId
                        ? $ownerUserId.':'.$channelId
                        : $ownerUserId.':contact:'.$contactEmail;

                    $selectedThread = collect($channels)->firstWhere('thread_key', $threadKey);



                    if (! $selectedThread) {

                        $selectedThread = [

                            'thread_key' => $threadKey,

                            'channel_id' => $channelId,

                            'owner_user_id' => $ownerUserId,

                            'owner_name' => 'Zoom user',

                            'label' => $channelId ? 'Team Chat channel' : ($contactEmail ?? 'Direct message'),

                            'thread_type' => $channelId ? 'channel' : 'contact',

                            'type' => $channelId ? 'channel' : 'dm',

                        ];

                    }



                    $messagePayload = $this->data->teamChatMessages((string) $ownerUserId, array_merge($filters, [

                        'to_channel' => $channelId,

                        'to_contact' => $contactEmail,

                        'page_token' => $request->get('msg_page_token'),

                    ]));



                    $messages = collect($messagePayload['messages'])->sortBy('date_time')->values()->all();

                    $messagesNextPageToken = $messagePayload['next_page_token'];

                }

            } catch (\Throwable $e) {

                $error = $this->zoom->humanizeError($e->getMessage());

            }

        } else {

            $error = 'Zoom is not configured.';

        }



        return view('communications.chat.index', [

            'mode' => 'chat',

            'routePrefix' => $this->routePrefix(),

            'filters' => $filters,

            'channels' => $channels,

            'nextPageToken' => $nextPageToken,

            'warning' => $warning,

            'error' => $error,

            'selectedThreadKey' => $ownerUserId && ($channelId || $contactEmail)

                ? ($channelId ? $ownerUserId.':'.$channelId : $ownerUserId.':contact:'.$contactEmail)

                : null,

            'selectedThread' => $selectedThread,

            'messages' => $messages,

            'messagesNextPageToken' => $messagesNextPageToken,

            'selectedOwnerUserId' => $ownerUserId,

            'selectedChannelId' => $channelId,

            'selectedContactEmail' => $contactEmail,

        ]);

    }



    protected function teamView(Request $request)

    {

        $filters = $this->filters($request);

        $error = null;

        $users = [];

        $queues = [];

        $queueWarning = null;



        if ($this->zoom->isConfigured()) {

            try {

                $users = collect($this->data->users())

                    ->map(fn (array $user) => [

                        'id' => $user['id'] ?? null,

                        'name' => trim(($user['first_name'] ?? '').' '.($user['last_name'] ?? '')) ?: ($user['email'] ?? 'Zoom user'),

                        'email' => $user['email'] ?? null,

                        'type' => $user['type'] ?? 'user',

                        'status' => $user['status'] ?? 'active',

                        'last_login_time' => $user['last_login_time'] ?? null,

                        'pmi' => $user['pmi'] ?? null,

                    ])

                    ->values()

                    ->all();



                $queuePayload = $this->data->callQueues($filters);

                $queues = $queuePayload['queues'];

                $queueWarning = $queuePayload['warning'];

            } catch (\Throwable $e) {

                $error = $this->zoom->humanizeError($e->getMessage());

            }

        } else {

            $error = 'Zoom is not configured.';

        }



        return view('communications.team.index', [

            'mode' => 'team',

            'routePrefix' => $this->routePrefix(),

            'filters' => $filters,

            'users' => $users,

            'queues' => $queues,

            'queueWarning' => $queueWarning,

            'error' => $error,

        ]);

    }



    protected function settingsView(Request $request)

    {

        return view('communications.settings.index', [

            'mode' => 'settings',

            'routePrefix' => $this->routePrefix(),

            'connection' => $this->zoom->connectionStatus(),

            'accountId' => $this->zoom->accountId(),

            'clientId' => $this->zoom->clientId(),

            'maskedSecret' => $this->zoom->maskedSecret(),

            'webhookSecret' => $this->zoom->webhookSecret(),

            'requiredScopes' => $this->zoom->requiredScopes(),

            'error' => $this->zoom->isConfigured() ? null : 'Zoom is not configured.',

        ]);

    }



    protected function resolveMode(Request $request): string

    {

        $mode = $request->get('mode', 'contacts');



        if ($mode === 'zoom') {

            $tab = $request->get('zoom_tab', 'settings');



            return match ($tab) {

                'logs' => 'calls',

                'recordings' => 'recordings',

                default => 'settings',

            };

        }



        $allowed = ['contacts', 'calls', 'dialer', 'recordings', 'voicemails', 'sms', 'chat', 'team', 'settings'];



        return in_array($mode, $allowed, true) ? $mode : 'contacts';

    }



    /**

     * @return array<string, mixed>

     */

    protected function filters(Request $request): array

    {

        $days = (int) config('integrations.communications.default_days', 14);



        return [

            'search' => $request->get('search'),

            'filter' => $request->get('filter'),

            'direction' => $request->get('direction'),

            'status' => $request->get('status'),

            'from' => $request->get('from', now()->subDays($days)->toDateString()),

            'to' => $request->get('to', now()->toDateString()),

            'page_token' => $request->get('page_token'),

        ];

    }



    protected function routePrefix(): string

    {

        return request()->is('admin*') ? 'admin.' : 'portal.';

    }



    /**

     * @param  array<string, mixed>  $log

     */

    protected function isMissedCall(array $log): bool

    {

        $result = strtolower((string) ($log['result'] ?? ''));



        return str_contains($result, 'miss') || str_contains($result, 'no answer');

    }

}


