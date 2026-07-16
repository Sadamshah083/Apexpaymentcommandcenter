<?php



namespace App\Http\Controllers;



use App\Services\Communications\CommunicationsDataService;

use App\Services\Communications\MorpheusHubService;

use App\Services\Communications\ZoomContactService;

use App\Services\Communications\CommunicationsInboxService;

use App\Services\Communications\CommunicationsCallHistoryService;

use App\Services\Communications\CommunicationsCallRecordingService;

use App\Services\Communications\CommunicationsPhoneNotesService;

use App\Services\Communications\DialerImportedLeadsService;

use App\Services\Communications\CommunicationsAccessService;

use App\Services\Communications\ZoomClickToCallService;

use App\Services\Integrations\ZoomApiService;

use App\Services\Workspace\WorkspaceContextService;

use App\Support\AdminModules;
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

        protected CommunicationsPhoneNotesService $phoneNotes,

        protected DialerImportedLeadsService $importedLeads,

        protected CommunicationsCallHistoryService $callHistory,

        protected WorkspaceContextService $workspaceContext,

        protected CommunicationsAccessService $access,

    ) {}



    public function index(Request $request)
    {
        if ($redirect = $this->communicationsHubUrlRedirect($request)) {
            return $redirect;
        }

        return $this->inboxView($request);
    }

    public function dialerCallLogs(Request $request)
    {
        $filters = $this->inbox->dialerCallLogFilters($this->filters($request));
        if ($request->boolean('recordings_only')) {
            $filters['recordings_only'] = true;
        }
        $recordingRole = strtolower(trim((string) $request->query('recording_role', '')));
        if (
            in_array($recordingRole, ['agent', 'team_lead'], true)
            && $this->access->canViewTeamRecordings(auth()->user(), $this->routePrefix())
        ) {
            $filters['recording_role'] = $recordingRole;
        }
        $offset = max(0, (int) $request->query('offset', 0));
        $perPage = min(50, max(1, (int) $request->query(
            'per_page',
            config('integrations.communications.list_page_size', 20),
        )));

        if (! auth()->check()) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        try {
            $payload = $this->inbox->paginateDialerCallLogs(
                $filters,
                $offset,
                $perPage,
                $this->routePrefix(),
                $this->zoom->isConfigured(),
            );

            return response()->json($payload);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => $this->zoom->humanizeError($e->getMessage()),
                'logs' => [],
                'next_offset' => $offset,
                'has_more' => false,
            ], 500);
        }
    }

    public function dialerImportedLeads(Request $request)
    {
        if (! auth()->check()) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $user = auth()->user();
        $routePrefix = $this->routePrefix();
        if (! $this->access->canAutoDial($user, $routePrefix)) {
            return response()->json(['message' => 'Auto dial is not available for this account.'], 403);
        }

        $workspace = $this->workspaceContext->resolveActiveWorkspace($user);
        if (! $workspace) {
            return response()->json(['message' => 'Workspace not found.'], 404);
        }

        $offset = max(0, (int) $request->query('offset', 0));
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $filters = [
            'campaign_id' => $request->query('campaign_id'),
            'pool' => $request->query('pool', 'callable'),
            'search' => $request->query('search'),
        ];

        if ($this->access->tierFor($user, $routePrefix) === 'agent') {
            // Agents only see their own assigned queue — no lead-pool switch.
            $filters['assigned_user_id'] = (int) $user->id;
            $filters['pool'] = 'assigned';
        }

        try {
            $payload = $this->importedLeads->paginate($workspace, $filters, $offset, $perPage);
            $payload['campaigns'] = $this->importedLeads->campaignOptions($workspace);

            return response()->json($payload);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => $this->zoom->humanizeError($e->getMessage()),
                'leads' => [],
                'next_offset' => $offset,
                'has_more' => false,
                'total' => 0,
                'campaigns' => [],
            ], 500);
        }
    }

    public function dialerDispositionSave(Request $request)
    {
        if (! auth()->check()) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // Unlock session so hangup/presence/originate are not blocked behind this save.
        \App\Support\ReleaseSessionLock::now($request);

        $user = auth()->user();
        $routePrefix = $this->routePrefix();
        // Anyone who can place calls must be able to save a disposition.
        if (! $this->access->canDial($user, $routePrefix)) {
            return response()->json(['message' => 'Dialer is not available for this account.'], 403);
        }

        $validated = $request->validate([
            'disposition' => ['required', 'string', 'max:120'],
            'call_uuid' => ['nullable', 'string', 'max:128'],
            'lead_id' => ['nullable', 'integer'],
            'phone' => ['nullable', 'string', 'max:32'],
            'note' => ['nullable', 'string', 'max:5000'],
            'in_call_notes' => ['nullable', 'string', 'max:5000'],
            'duration_sec' => ['nullable', 'integer', 'min:0', 'max:86400'],
        ]);

        $workspace = $this->workspaceContext->resolveActiveWorkspace($user);
        if (! $workspace) {
            return response()->json(['message' => 'Workspace not found.'], 404);
        }

        $disposition = trim((string) $validated['disposition']);
        $note = filled($validated['note'] ?? null) ? trim((string) $validated['note']) : null;
        $inCallNotes = filled($validated['in_call_notes'] ?? null) ? trim((string) $validated['in_call_notes']) : null;
        $uuid = trim((string) ($validated['call_uuid'] ?? ''));
        $phone = trim((string) ($validated['phone'] ?? ''));
        $durationSec = (int) ($validated['duration_sec'] ?? 0);
        $leadId = filled($validated['lead_id'] ?? null) ? (int) $validated['lead_id'] : null;

        // Morpheus disposition is best-effort and never blocks the agent UI / next dial.
        if ($uuid !== '') {
            $zoomDisposition = $disposition;
            $zoomNote = $note;
            dispatch(function () use ($uuid, $zoomDisposition, $zoomNote) {
                try {
                    app(\App\Services\Integrations\ZoomApiService::class)->dispositionCall($uuid, [
                        'disposition' => $zoomDisposition,
                        'note' => $zoomNote,
                        'update_lead' => false,
                    ]);
                } catch (\Throwable) {
                    // Local disposition history is still recorded below.
                }
            })->afterResponse();
        }

        $callLog = $this->callHistory->recordDialerDisposition($workspace, [
            'call_uuid' => $uuid !== '' ? $uuid : null,
            'phone' => $phone !== '' ? $phone : null,
            'disposition' => $disposition,
            'note' => $note,
            'in_call_notes' => $inCallNotes,
            'duration_sec' => $durationSec > 0 ? $durationSec : null,
            'user' => $user,
            'lead_id' => $leadId,
        ]);

        $leadPayload = null;
        $leadName = '';
        $leadContact = '';
        $lead = null;

        if ($leadId) {
            $lead = \App\Models\WorkflowLead::query()
                ->select([
                    'id',
                    'workflow_id',
                    'business_name',
                    'owner_name',
                    'notes',
                    'setter_status',
                    'contact_attempts',
                    'last_contacted_at',
                ])
                ->whereKey($leadId)
                ->whereHas('workflow', fn ($q) => $q->where('workspace_id', $workspace->id))
                ->first();
        }

        // Fast path only — skip expensive fuzzy phone scans when lead_id was sent.
        if (! $lead && $phone !== '' && ! $leadId) {
            $digits = preg_replace('/\D+/', '', $phone) ?? '';
            $tail = strlen($digits) >= 10 ? substr($digits, -10) : $digits;
            $lead = \App\Models\WorkflowLead::query()
                ->select([
                    'id',
                    'workflow_id',
                    'business_name',
                    'owner_name',
                    'notes',
                    'setter_status',
                    'contact_attempts',
                    'last_contacted_at',
                ])
                ->whereHas('workflow', fn ($q) => $q->where('workspace_id', $workspace->id))
                ->where(function ($q) use ($phone, $tail) {
                    $q->where('normalized_phone', $phone)
                        ->orWhere('direct_phone', $phone)
                        ->orWhere('input_phone', $phone);
                    if ($tail !== '') {
                        $q->orWhere('normalized_phone', 'like', '%'.$tail);
                    }
                })
                ->when(
                    $this->access->tierFor($user, $routePrefix) === 'agent',
                    fn ($q) => $q->where(function ($assigned) use ($user) {
                        $assigned->where('assigned_user_id', (int) $user->id)
                            ->orWhere('assigned_setter_id', (int) $user->id);
                    })
                )
                ->orderByDesc('id')
                ->limit(1)
                ->first();
        }

        if ($lead) {
            $setterStatus = $this->importedLeads->dispositionToSetterStatus($disposition);
            $updates = [
                'last_contacted_at' => now(),
                'contact_attempts' => max(1, (int) ($lead->contact_attempts ?? 0) + 1),
            ];

            if ($setterStatus) {
                $updates['setter_status'] = $setterStatus;
            }

            if ($note) {
                $updates['notes'] = trim(((string) ($lead->notes ?? ''))."\n".$note);
            }

            $lead->update($updates);
            $leadPayload = [
                'id' => (int) $lead->id,
                'removed' => true,
            ];
            $leadName = filled($lead->business_name)
                ? (string) $lead->business_name
                : (string) ($lead->owner_name ?? '');
            $leadContact = filled($lead->owner_name) && filled($lead->business_name)
                ? (string) $lead->owner_name
                : '';
        }

        CommunicationsInboxService::bumpDialerLogsCacheVersion();

        // Lightweight response — skip enrichDialerCallLogs* (those were making Save take ~5s).
        $duration = (int) ($callLog->duration_sec ?? $durationSec);
        $phoneDisplay = $phone !== '' ? $phone : (string) ($callLog->to_phone ?? '');
        $formatted = [
            'id' => $callLog->morpheus_call_uuid ?: ('local:'.$callLog->id),
            'direction' => 'outbound',
            'phone' => $phoneDisplay,
            'phone_display' => $phoneDisplay,
            'to' => $phoneDisplay !== '' ? $phoneDisplay : '—',
            'to_phone' => $phoneDisplay,
            'disposition' => $disposition,
            'result' => $duration > 0 ? 'connected' : 'no-answer',
            'note' => $note,
            'call_note' => $note,
            'in_call_notes' => $inCallNotes,
            'duration' => $duration,
            'duration_sec' => $duration,
            'duration_label' => $duration > 0 ? $duration.'s' : '0s',
            'time_ago' => 'just now',
            'lead_id' => $lead ? (int) $lead->id : $leadId,
            'lead_name' => $leadName,
            'lead_contact' => $leadContact,
            'agent_name' => $user?->name,
            'user_id' => $user?->id,
            'source' => 'local_history',
        ];

        return response()->json([
            'saved' => true,
            'disposition' => $disposition,
            'lead' => $leadPayload,
            'lead_removed' => $leadPayload !== null,
            'call_log' => $formatted,
            'next_call_delay_sec' => max(0, (int) config('integrations.communications.next_call_delay_sec', 6)),
        ]);
    }

    public function dialerPhoneNoteShow(Request $request)
    {
        if (! auth()->check()) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $phone = trim((string) $request->query('phone', ''));
        if ($phone === '') {
            return response()->json(['message' => 'Phone is required.'], 422);
        }

        $workspace = $this->workspaceContext->resolveActiveWorkspace(auth()->user());
        if (! $workspace) {
            return response()->json(['message' => 'Workspace not found.'], 404);
        }

        $callLogRef = trim((string) $request->query('call_log_ref', ''));

        return response()->json([
            'phone' => $phone,
            'phone_note' => $this->phoneNotes->bodyForPhone($workspace, $phone),
            'call_note' => $this->callHistory->callNoteForRef($workspace, $callLogRef),
            'call_log_ref' => $callLogRef !== '' ? $callLogRef : null,
        ]);
    }

    public function dialerPhoneNoteSave(Request $request)
    {
        if (! auth()->check()) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'body' => ['nullable', 'string', 'max:5000'],
        ]);

        $workspace = $this->workspaceContext->resolveActiveWorkspace(auth()->user());
        if (! $workspace) {
            return response()->json(['message' => 'Workspace not found.'], 404);
        }

        try {
            $note = $this->phoneNotes->upsertForPhone(
                $workspace,
                auth()->user(),
                $validated['phone'],
                $validated['body'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        CommunicationsInboxService::bumpDialerLogsCacheVersion();

        return response()->json([
            'phone' => $validated['phone'],
            'phone_note' => (string) ($note->body ?? ''),
            'saved_at' => $note->updated_at?->toIso8601String(),
        ]);
    }

    public function dialerCallNoteSave(Request $request)
    {
        if (! auth()->check()) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'call_log_ref' => ['nullable', 'string', 'max:128'],
            'call_uuid' => ['nullable', 'string', 'max:128'],
            'phone' => ['nullable', 'string', 'max:32'],
            'note' => ['nullable', 'string', 'max:5000'],
            'in_call_notes' => ['nullable', 'string', 'max:5000'],
            'save_phone_note' => ['nullable', 'boolean'],
        ]);

        $workspace = $this->workspaceContext->resolveActiveWorkspace(auth()->user());
        if (! $workspace) {
            return response()->json(['message' => 'Workspace not found.'], 404);
        }

        $user = auth()->user();
        $note = $validated['note'] ?? null;
        $inCallNotes = array_key_exists('in_call_notes', $validated)
            ? trim((string) ($validated['in_call_notes'] ?? ''))
            : null;
        $log = null;

        if (filled($validated['call_log_ref'] ?? null)) {
            $log = $this->callHistory->updateCallNote($workspace, (string) $validated['call_log_ref'], $note, $user);
        } elseif (filled($validated['call_uuid'] ?? null)) {
            $log = $this->callHistory->updateCallNoteByUuid($workspace, (string) $validated['call_uuid'], $note, $user);
        }

        if ($log && $inCallNotes !== null) {
            $meta = $log->meta ?? [];
            if ($inCallNotes === '') {
                unset($meta['in_call_notes']);
            } else {
                $meta['in_call_notes'] = $inCallNotes;
            }
            $log->update(['meta' => $meta]);
            $log = $log->fresh() ?? $log;
        }

        if (filled($validated['phone'] ?? null) && ($validated['save_phone_note'] ?? false)) {
            try {
                $this->phoneNotes->upsertForPhone($workspace, $user, (string) $validated['phone'], $note);
            } catch (\InvalidArgumentException) {
                // Phone-level note is optional when saving call note.
            }
        }

        if (! $log && ! filled($validated['phone'] ?? null)) {
            return response()->json(['message' => 'Call reference or phone is required.'], 422);
        }

        CommunicationsInboxService::bumpDialerLogsCacheVersion();

        return response()->json([
            'call_log_ref' => $log?->morpheus_call_uuid ?: ($log ? 'local:'.$log->id : ($validated['call_log_ref'] ?? null)),
            'call_note' => (string) ($log?->note ?? $note ?? ''),
            'in_call_notes' => (string) data_get($log?->meta, 'in_call_notes', $inCallNotes ?? ''),
            'phone_note' => filled($validated['phone'] ?? null)
                ? $this->phoneNotes->bodyForPhone($workspace, (string) $validated['phone'])
                : null,
            'saved' => true,
        ]);
    }

    public function dialerSyncCallRecording(Request $request)
    {
        if (! auth()->check()) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'call_log_ref' => ['nullable', 'string', 'max:128'],
            'call_uuid' => ['nullable', 'string', 'max:128'],
        ]);

        $workspace = $this->workspaceContext->resolveActiveWorkspace(auth()->user());
        if (! $workspace) {
            return response()->json(['message' => 'Workspace not found.'], 404);
        }

        $ref = (string) ($validated['call_log_ref'] ?? $validated['call_uuid'] ?? '');
        if ($ref === '') {
            return response()->json(['message' => 'Call reference is required.'], 422);
        }

        $log = $this->callHistory->resolveCallLog($workspace, $ref);
        $recordingService = app(CommunicationsCallRecordingService::class);

        if ($log) {
            $log = $recordingService->resolveAndPersist($log);
            $recording = $recordingService->recordingFieldsForHubLog($log);
        } else {
            $fileId = $this->zoom->findRecordingFileIdForCall($ref);
            $recording = [
                'has_recording_media' => filled($fileId),
                'recording_id' => $fileId,
                'recording_source' => 'morpheus',
                'call_reference_id' => $ref,
                'recording_status' => filled($fileId)
                    ? CommunicationsCallRecordingService::STATUS_READY
                    : CommunicationsCallRecordingService::STATUS_UNAVAILABLE,
            ];
        }

        CommunicationsInboxService::bumpDialerLogsCacheVersion();
        $routePrefix = $this->routePrefix();
        $hasRecording = (bool) ($recording['has_recording_media'] ?? false);
        $callRef = (string) ($recording['call_reference_id'] ?? $ref);

        return response()->json([
            'recording_status' => $recording['recording_status'] ?? 'none',
            'has_recording' => $hasRecording,
            'recording_id' => $recording['recording_id'] ?? null,
            'play_url' => $hasRecording
                ? route($routePrefix.'communications.zoom.recordings.media', [
                    'recordingId' => $recording['recording_id'],
                    'source' => $recording['recording_source'] ?? 'morpheus',
                    'action' => 'play',
                    'call_ref' => $callRef,
                ])
                : null,
            'download_url' => $hasRecording
                ? route($routePrefix.'communications.zoom.recordings.media', [
                    'recordingId' => $recording['recording_id'],
                    'source' => $recording['recording_source'] ?? 'morpheus',
                    'action' => 'download',
                    'call_ref' => $callRef,
                ])
                : null,
        ]);
    }

    protected function communicationsHubUrlRedirect(Request $request): ?\Illuminate\Http\RedirectResponse
    {
        $allowed = array_filter([
            'number' => $request->query('number'),
        ], fn ($value) => filled($value));

        $disallowed = collect($request->query())
            ->except(array_keys($allowed))
            ->isNotEmpty();

        if (! $disallowed) {
            return null;
        }

        return redirect()->route($this->routePrefix().'communications.index', $allowed);
    }



    public function showContact(string $contactKey, Request $request)

    {

        return redirect()->route($this->routePrefix().'communications.index');

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

        try {

            return view('communications.inbox.index', $this->inbox->build($request, $this->routePrefix()));

        } catch (\Throwable $e) {

            report($e);

            return view('communications.inbox.index', $this->inbox->buildDegraded($request, $this->routePrefix(), $e));

        }

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

        $prevPageToken = null;

        $warnings = [];

        $stats = [];

        $perPage = (int) config('integrations.communications.list_page_size', 20);

        $currentOffset = is_numeric($filters['page_token'] ?? null) ? (int) $filters['page_token'] : 0;

        $currentPage = (int) floor($currentOffset / max($perPage, 1)) + 1;



        if ($this->zoom->isConfigured()) {

            try {

                if (auth()->check()) {
                    $data = app(\App\Services\Communications\CommunicationsDataService::class);
                    $payload = $data->callLogs(array_merge($filters, ['per_page' => $perPage]), 1, true);
                    $callLogs = $this->filterCallLogs($payload['logs'] ?? [], $filters);
                    $nextPageToken = $payload['next_page_token'] ?? null;
                    $prevPageToken = $currentOffset > 0 ? (string) max(0, $currentOffset - $perPage) : null;

                    if (filled($payload['warning'] ?? null)) {
                        $warnings[] = $payload['warning'];
                    }

                    $stats = $data->callStats($filters);
                }

            } catch (\Throwable $e) {

                $error = $this->zoom->humanizeError($e->getMessage());

            }

        } else {

            $error = 'Morpheus CX is not configured.';

        }



        return view('communications.calls.index', [

            'mode' => 'calls',

            'routePrefix' => $this->routePrefix(),

            'filters' => $filters,

            'callLogs' => $callLogs,

            'nextPageToken' => $nextPageToken,

            'prevPageToken' => $prevPageToken,

            'currentPage' => $currentPage,

            'perPage' => $perPage,

            'warnings' => $warnings,

            'error' => $error,

            'stats' => $stats,

        ]);

    }



    /**
     * @param  array<int, array<string, mixed>>  $logs
     * @return array<int, array<string, mixed>>
     */
    protected function filterCallLogs(array $logs, array $filters): array

    {

        return collect($logs)

            ->when(filled($filters['direction'] ?? null), fn ($collection) => $collection->where('direction', $filters['direction']))

            ->when(($filters['filter'] ?? '') === 'recorded', fn ($collection) => $collection->filter(

                fn (array $log) => ($log['recording'] ?? '') === 'Yes' || ($log['has_recording_media'] ?? false)

            ))

            ->when(($filters['filter'] ?? '') === 'missed', fn ($collection) => $collection->filter(function (array $log) {

                $result = strtolower((string) ($log['result'] ?? ''));

                return str_contains($result, 'miss')

                    || str_contains($result, 'no answer')

                    || str_contains($result, 'no_answer');

            }))

            ->values()

            ->all();

    }



    protected function dialerView(Request $request)

    {

        $filters = $this->filters($request);

        $error = null;

        $warning = null;

        $phoneUsers = [];
        $morpheusExtensions = [];
        $recentNumbers = [];

        $prefillNumber = $request->get('number');

        $routePrefix = $this->routePrefix();



        if ($this->zoom->isConfigured()) {

            try {

                if (auth()->check()) {
                    $workspace = app(\App\Services\Workspace\WorkspaceContextService::class)
                        ->resolveActiveWorkspace(auth()->user());
                    $morpheusExtensions = app(\App\Services\Communications\CommunicationsAgentService::class)
                        ->dialerExtensionsFast(auth()->user(), $workspace, $routePrefix);
                    $recentNumbers = collect(
                        app(\App\Services\Communications\CommunicationsCallHistoryService::class)
                            ->listForHub($workspace, $filters)
                    )->flatMap(fn (array $log) => array_filter([
                        $log['from_phone'] ?? null,
                        $log['to_phone'] ?? null,
                    ]))->unique()->take(12)->values()->all();
                }

            } catch (\Throwable $e) {

                $error = $this->zoom->humanizeError($e->getMessage());

            }

        } else {

            $error = 'Morpheus CX is not configured.';

        }



        return view('communications.dialer.index', [

            'mode' => 'dialer',

            'routePrefix' => $routePrefix,

            'filters' => $filters,

            'phoneUsers' => $phoneUsers,

            'morpheusExtensions' => $morpheusExtensions,

            'recentNumbers' => $recentNumbers,

            'prefillNumber' => is_string($prefillNumber) ? $prefillNumber : null,

            'defaultCallerId' => auth()->check()
                ? (app(\App\Services\Communications\CommunicationsAgentService::class)
                    ->extensionForUser(auth()->user()) ?? config('integrations.communications.default_caller_id'))
                : config('integrations.communications.default_caller_id'),

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

            $error = 'Morpheus CX is not configured.';

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

            $error = 'Morpheus CX is not configured.';

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

            $error = 'Morpheus CX is not configured.';

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
        app(MorpheusHubService::class)->bustCache();
        app(\App\Services\Integrations\MorpheusCircuitBreaker::class)->reset();

        $this->zoom->clearAccessTokenCache();

        \Illuminate\Support\Facades\Cache::forget('integrations.morpheus.connection_status');
        \Illuminate\Support\Facades\Cache::forget('integrations.morpheus.connection_diagnostics');
        \Illuminate\Support\Facades\Cache::forget('zoom.connection.diagnostics');

        return redirect()->route($this->routePrefix().'communications.index')
            ->with('success', 'Communications cache refreshed. Fresh Morpheus CX data will load on your next visit.');

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



            return redirect()->route($this->routePrefix().'communications.index')
                ->with('success', 'SMS sent successfully.');

        } catch (\Throwable $e) {

            return back()->withInput()->with('error', $this->zoom->humanizeError($e->getMessage()));

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

            return redirect()->route($this->routePrefix().'communications.index')
                ->with('success', 'Chat message sent.');
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

            $error = 'Morpheus CX is not configured.';

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

            $error = 'Morpheus CX is not configured.';

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

            'error' => $this->zoom->isConfigured() ? null : 'Morpheus CX is not configured.',

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


