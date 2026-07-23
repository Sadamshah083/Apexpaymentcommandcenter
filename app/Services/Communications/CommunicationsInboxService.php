<?php

namespace App\Services\Communications;

use App\Models\Workspace;
use App\Services\Integrations\ZoomApiService;
use App\Services\Workspace\WorkspaceContextService;
use App\Support\SimplePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class CommunicationsInboxService
{
  public const CHANNELS = [
    'inbox' => ['label' => 'Inbox', 'icon' => 'inbox'],
    'calls' => ['label' => 'Calls', 'icon' => 'phone'],
    'recordings' => ['label' => 'Recordings', 'icon' => 'recording'],
    'voicemail' => ['label' => 'Voicemail', 'icon' => 'voicemail'],
    'sms' => ['label' => 'SMS', 'icon' => 'sms'],
    'chat' => ['label' => 'Team chat', 'icon' => 'chat'],
    'queues' => ['label' => 'Queues', 'icon' => 'queue'],
    'conferences' => ['label' => 'Conferences', 'icon' => 'conference'],
    'leads' => ['label' => 'Leads', 'icon' => 'leads'],
    'campaigns' => ['label' => 'Campaigns', 'icon' => 'campaigns'],
    'lists' => ['label' => 'Lists', 'icon' => 'lists'],
    'extensions' => ['label' => 'Extensions', 'icon' => 'extensions'],
    'agents' => ['label' => 'Phone agents', 'icon' => 'agents'],
    'team' => ['label' => 'Team', 'icon' => 'team'],
  ];

  public function __construct(
    protected ZoomApiService $zoom,
    protected CommunicationsDataService $data,
    protected ZoomContactService $contacts,
    protected ZoomClickToCallService $clickToCall,
    protected MorpheusHubService $morpheusHub,
    protected CommunicationsCallHistoryService $callHistory,
    protected WorkspaceContextService $workspaceContext,
    protected CommunicationsAccessService $access,
    protected CommunicationsPhoneNotesService $phoneNotes,
    protected CommunicationsLeadLookupService $leadLookup,
  ) {}

  /**
   * @return array<string, mixed>
   */
  public function build(Request $request, string $routePrefix): array
  {
    $filters = $this->filters($request);
    $channel = $this->resolveChannel($request);
    $panel = $this->resolvePanel($request, $channel);
    $user = Auth::user();
    $scope = $this->access->clampScope($request, $routePrefix, $user, $channel, $panel);
    $channel = $scope['channel'];
    $panel = $scope['panel'];
    $hubAccess = $this->access->viewMeta($user, $routePrefix);
    $canConfigure = $hubAccess['canConfigure'];
    $errors = [];
    $warnings = [];

    $connection = $this->zoom->isConfigured()
      ? ($panel === 'settings'
        ? $this->zoom->connectionStatus()
        : (($channel === 'inbox' && $panel === 'empty') || $channel === 'calls' || $panel === 'dialer'
          // Dialer login: always On when Morpheus is configured (never stale Off from probe cache).
          ? [
            'connected' => true,
            'message' => 'Logged in — telephony available.',
            'expires_at' => null,
          ]
          : $this->zoom->connectionStatus()))
      : ['connected' => false, 'message' => 'Morpheus CX is not configured.', 'expires_at' => null];

    if (! $this->zoom->isConfigured()) {
      $errors[] = 'Morpheus CX is not configured.';
    }

    if ($panel === 'settings') {
      return [
        'channel' => $channel,
        'panel' => $panel,
        'filters' => $filters,
        'routePrefix' => $routePrefix,
        'channels' => $this->access->channelsFor($user, $routePrefix),
        'sidebarItems' => [],
        'contacts' => [],
        'callLogs' => [],
        'callStats' => [],
        'voiceMails' => [],
        'smsSessions' => [],
        'chatChannels' => [],
        'recordings' => [],
        'teamUsers' => [],
        'teamQueues' => [],
        'queueWarning' => null,
        'morpheusQueues' => [],
        'morpheusConferences' => [],
        'morpheusLeads' => [],
        'morpheusCampaigns' => [],
        'morpheusLists' => [],
        'morpheusExtensions' => [],
        'morpheusUsers' => [],
        'activeCalls' => [],
        'selectedQueueWaiting' => [],
        'selectedConferenceMembers' => [],
        'phoneUsers' => [],
        'recentNumbers' => [],
        'nextPageToken' => null,
        'selectedContact' => null,
        'timeline' => [],
        'contactStats' => [],
        'smsSession' => null,
        'smsMessages' => [],
        'smsMessagesNextPageToken' => null,
        'selectedThread' => null,
        'chatMessages' => [],
        'chatMessagesNextPageToken' => null,
        'selectedCall' => null,
        'selectedVoicemail' => null,
        'selectedRecording' => null,
        'connection' => $connection,
        'connectionDiagnostics' => $this->zoom->connectionDiagnostics(),
        'settings' => [
          'accountId' => $this->zoom->accountId(),
          'clientId' => $this->zoom->clientId(),
          'maskedSecret' => $this->zoom->maskedSecret(),
          'webhookSecret' => $this->zoom->webhookSecret(),
          'requiredScopes' => $this->zoom->requiredScopes(),
        ],
        'outboundCalling' => $this->zoom->outboundCallingProfile(),
        'defaultCallerId' => $this->resolveDefaultCallerId(),
        'prefillNumber' => $this->resolvePrefillDialNumber($request),
        'error' => $errors !== [] ? implode(' ', array_unique($errors)) : null,
        'warnings' => [],
        'clickToCall' => $this->clickToCall,
        'channelCounts' => [],
        'hubAccess' => $hubAccess,
      ];
    }

    $channelCounts = [];
    $sidebarItems = [];
    $listPagination = null;
    $panelPagination = null;
    $contacts = [];
    $callLogs = [];
    $callStats = [];
    $voiceMails = [];
    $smsSessions = [];
    $chatChannels = [];
    $recordings = [];
    $teamUsers = [];
    $teamQueues = [];
    $queueWarning = null;
    $morpheusQueues = [];
    $morpheusConferences = [];
    $morpheusLeads = [];
    $morpheusCampaigns = [];
    $morpheusLists = [];
    $morpheusExtensions = [];
    $morpheusUsers = [];
    $activeCalls = [];
    $communicationAgents = [];
    $suggestedExtensionNum = (string) (config('integrations.communications.default_caller_id') ?: '1020');
    $availablePhoneLines = [];
    $selectedQueueWaiting = [];
    $selectedConferenceMembers = [];
    $phoneUsers = [];
    $recentNumbers = [];
    $nextPageToken = null;

    $selectedContact = null;
    $timeline = [];
    $contactStats = [];
    $smsSession = null;
    $smsMessages = [];
    $smsMessagesNextPageToken = null;
    $selectedThread = null;
    $chatMessages = [];
    $chatMessagesNextPageToken = null;
    $selectedCall = null;
    $selectedVoicemail = null;
    $selectedRecording = null;
    $workspace = $user ? $this->workspaceContext->resolveActiveWorkspace($user) : null;

    if ($this->zoom->isConfigured()) {
      try {
        $fastDialerShell = $panel === 'dialer';
        $morpheusReachable = (bool) ($connection['connected'] ?? false);
        $loadDialerExtras = in_array($channel, ['inbox', 'calls'], true) || $panel === 'dialer';
        $needsPhoneUsers = ! $fastDialerShell && $channel !== 'calls' && (
          in_array($channel, ['inbox', 'sms', 'team'], true)
          || in_array($panel, ['dialer', 'sms'], true)
        );

        if ($needsPhoneUsers) {
          $phonePayload = $this->data->phoneUsers($filters);
          $phoneUsers = $phonePayload['users'];
          if ($phonePayload['warning'] ?? null) {
            $warnings[] = $phonePayload['warning'];
          }
        }

        $loadCallSummary = ! in_array($channel, ['inbox', 'calls'], true);
        $loadVmSummary = ! $fastDialerShell && ! in_array($channel, ['inbox', 'calls', 'voicemail'], true);
        $loadSmsSummary = ! $fastDialerShell && ! in_array($channel, ['inbox', 'calls', 'sms'], true);
        $summaryStats = [];
        $summaryVms = [];
        $summarySms = [];

        if ($loadCallSummary) {
          $summaryCalls = $this->safeDataLoad(
            fn () => $this->data->callLogs($filters, 1),
            ['logs' => [], 'next_page_token' => null, 'warning' => null],
            $warnings,
          );
          if ($summaryCalls['warning'] ?? null) {
            $warnings[] = $summaryCalls['warning'];
          }
          $summaryStats = $this->data->callStatsFromLogs($summaryCalls['logs']);
        }

        if ($loadVmSummary) {
          $summaryVmPayload = $this->safeDataLoad(
            fn () => $this->data->voiceMails($filters),
            ['voice_mails' => [], 'warning' => null],
            $warnings,
          );
          $summaryVms = $summaryVmPayload['voice_mails'];
          if ($summaryVmPayload['warning'] ?? null) {
            $warnings[] = $summaryVmPayload['warning'];
          }
        }

        if ($loadSmsSummary) {
          $summarySmsPayload = $this->safeDataLoad(
            fn () => $this->data->smsSessions($filters),
            ['sessions' => [], 'warning' => null],
            $warnings,
          );
          $summarySms = $summarySmsPayload['sessions'];
          if ($summarySmsPayload['warning'] ?? null) {
            $warnings[] = $summarySmsPayload['warning'];
          }
        }

        $enrichCallRecordings = $channel === 'recordings'
          || ($filters['filter'] ?? '') === 'recorded'
          || $panel === 'dialer';

        if (in_array($channel, ['inbox', 'calls'], true)) {
          // Dialer document paint must stay local — Morpheus CDR/active calls load via AJAX.
          if ($fastDialerShell) {
            $callLogs = [];
            $callStats = [];
            $activeCalls = [];
            $recentNumbers = [];
          } elseif (auth()->check()) {
            $workspace = $this->workspaceContext->resolveActiveWorkspace(auth()->user()) ?? $workspace;
            $scopedFilters = $this->applyCallLogActorScope($filters, $user, $routePrefix);
            $callLogs = $this->callHistory->listForHub($workspace, $scopedFilters);
            // Apply direction/missed/recorded filters to local history too
            if ($channel === 'calls') {
              $callLogs = $this->filterCallLogs($callLogs, $scopedFilters);
            }
            $callStats = $this->data->callStatsFromLogs($callLogs);

            if (! $fastDialerShell && $channel === 'inbox' && $morpheusReachable && empty($scopedFilters['actor_scoped'])) {
              $callPayload = $this->safeDataLoad(
                fn () => $this->data->callLogs(
                  $scopedFilters,
                  (int) config('integrations.communications.detail_max_pages', 1),
                  $enrichCallRecordings,
                ),
                ['logs' => [], 'next_page_token' => null, 'warning' => null],
                $warnings,
              );
              if ($callPayload['warning'] ?? null) {
                $warnings[] = $callPayload['warning'];
              }
              $callLogs = $this->filterCallLogs(
                $this->mergeLocalCallHistory($callPayload['logs'], $scopedFilters, $warnings),
                $scopedFilters,
              );
              $nextPageToken = $callPayload['next_page_token'];
              $callStats = $this->data->callStatsFromLogs($callLogs);
            }
          } else {
          $callPayload = $this->safeDataLoad(
            fn () => $this->data->callLogs(
              $filters,
              (int) config('integrations.communications.detail_max_pages', 1),
              $enrichCallRecordings,
            ),
            ['logs' => [], 'next_page_token' => null, 'warning' => null],
            $warnings,
          );
          if ($callPayload['warning'] ?? null) {
            $warnings[] = $callPayload['warning'];
          }
          $callLogs = $this->filterCallLogs(
            $this->mergeLocalCallHistory($callPayload['logs'], $filters, $warnings),
            $filters
          );
          $nextPageToken = $callPayload['next_page_token'];
          $callStats = $this->data->callStatsFromLogs($callLogs);
          }

          if ($loadDialerExtras && ! $fastDialerShell) {
            try {
              $recentNumbers = $this->data->recentDialNumbers($filters, 12, $callLogs);
            } catch (\Throwable $e) {
              $warnings[] = $this->zoom->humanizeError($e->getMessage());
            }

            $activeCalls = $this->safeDataLoad(
              fn () => $this->morpheusHub->activeCalls(),
              [],
              $warnings,
            );
          }
        } elseif ($callStats === []) {
          $callStats = $summaryStats;
        }

        if ($channel === 'inbox' && ! $fastDialerShell) {
          $contactPayload = $this->safeDataLoad(
            fn () => $this->contacts->buildIndexPayload($filters, $callLogs, $nextPageToken),
            ['contacts' => [], 'error' => null],
            $warnings,
          );
          $contacts = $contactPayload['contacts'];
          if ($contactPayload['error']) {
            $errors[] = $contactPayload['error'];
          }
        }

        if (in_array($channel, ['voicemail', 'inbox'], true) && ! $fastDialerShell) {
          $vmPayload = $this->safeDataLoad(
            fn () => $this->data->voiceMails($filters),
            ['voice_mails' => [], 'warning' => null],
            $warnings,
          );
          $voiceMails = $vmPayload['voice_mails'];
          if ($vmPayload['warning']) {
            $warnings[] = $vmPayload['warning'];
          }
        }

        if ($channel === 'voicemail') {
          $voiceMails = $this->filterVoiceMails($voiceMails, $filters);
        }

        if (in_array($channel, ['sms', 'inbox'], true) && ! $fastDialerShell) {
          $smsPayload = $this->safeDataLoad(
            fn () => $this->data->smsSessions($filters),
            ['sessions' => [], 'warning' => null],
            $warnings,
          );
          $smsSessions = $smsPayload['sessions'];
          if ($smsPayload['warning']) {
            $warnings[] = $smsPayload['warning'];
          }
        }

        if ($channel === 'chat') {
          $chatPayload = $this->safeDataLoad(
            fn () => $this->data->teamChatChannels($filters),
            ['channels' => [], 'warning' => null],
            $warnings,
          );
          $chatChannels = $chatPayload['channels'];
          if ($chatPayload['warning']) {
            $warnings[] = $chatPayload['warning'];
          }
        }

        if ($channel === 'recordings') {
          $scopedFilters = $this->applyCallLogActorScope($filters, $user, $routePrefix);
          if (! empty($scopedFilters['actor_scoped'])) {
            $agentLogs = $this->resolveDialerCallLogs($scopedFilters, false, $routePrefix);
            $agentLogs = $this->data->enrichCallLogsWithRecordings($agentLogs, $scopedFilters);
            $recordings = array_values(array_filter(
              $agentLogs,
              fn (array $log) => ! empty($log['has_recording_media']) && ! empty($log['recording_id']),
            ));
            $nextPageToken = null;
          } else {
          $recPayload = $this->safeDataLoad(
            fn () => $this->data->recordings($filters),
            ['recordings' => [], 'next_page_token' => null, 'warnings' => []],
            $warnings,
          );
          $recordings = $recPayload['recordings'];
          $nextPageToken = $recPayload['next_page_token'];
          $warnings = array_merge($warnings, $recPayload['warnings'] ?? []);
          }
        }

        if ($canConfigure && ($channel === 'team' || $channel === 'queues')) {
          $morpheusQueues = $this->safeDataLoad(fn () => $this->morpheusHub->queues(), [], $warnings);
          $teamQueues = $morpheusQueues;
        }

        if ($canConfigure && $channel === 'conferences') {
          $morpheusConferences = $this->safeDataLoad(fn () => $this->morpheusHub->conferences(), [], $warnings);
        }

        if ($canConfigure && $channel === 'leads') {
          $morpheusLeads = $this->safeDataLoad(
            fn () => $this->morpheusHub->leads(['search' => $filters['search'] ?? null]),
            [],
            $warnings,
          );
          $morpheusLists = $this->safeDataLoad(fn () => $this->morpheusHub->lists(), [], $warnings);
        }

        if ($canConfigure && $channel === 'campaigns') {
          $morpheusCampaigns = $this->safeDataLoad(fn () => $this->morpheusHub->campaigns(), [], $warnings);
        }

        if ($canConfigure && $channel === 'lists') {
          $morpheusLists = $this->safeDataLoad(fn () => $this->morpheusHub->lists(), [], $warnings);
          $morpheusCampaigns = $this->safeDataLoad(fn () => $this->morpheusHub->campaigns(), [], $warnings);
        }

        if ($canConfigure && ($channel === 'extensions' || $channel === 'agents')) {
          $morpheusExtensions = $this->safeDataLoad(fn () => $this->morpheusHub->extensions(), [], $warnings);
          if ($channel === 'extensions' && auth()->check()) {
            $availablePhoneLines = $this->safeDataLoad(function () {
              $workspace = app(\App\Services\Workspace\WorkspaceContextService::class)
                ->resolveActiveWorkspace(auth()->user());

              return app(CommunicationsAgentService::class)->availablePhoneLines($workspace);
            }, [], $warnings);
          }
        } elseif ($loadDialerExtras) {
          $useFastExtensions = $fastDialerShell || $channel === 'calls' || ! $morpheusReachable;
          $morpheusExtensions = $useFastExtensions
            ? $this->safeDataLoad(function () use ($routePrefix) {
              if (! auth()->check()) {
                return [];
              }
              $workspace = app(\App\Services\Workspace\WorkspaceContextService::class)
                ->resolveActiveWorkspace(auth()->user());

              return app(CommunicationsAgentService::class)->dialerExtensionsFast(
                auth()->user(),
                $workspace,
                $routePrefix,
              );
            }, [], $warnings)
            : $this->safeDataLoad(function () use ($routePrefix) {
              if (! auth()->check()) {
                return [];
              }
              $workspace = app(\App\Services\Workspace\WorkspaceContextService::class)
                ->resolveActiveWorkspace(auth()->user());

              return app(CommunicationsAgentService::class)->dialerExtensionsFor(
                auth()->user(),
                $workspace,
                $routePrefix,
              );
            }, [], $warnings);
        }

        if ($canConfigure && $channel === 'agents' && auth()->check()) {
          $communicationAgents = $this->safeDataLoad(function () {
            $workspace = app(\App\Services\Workspace\WorkspaceContextService::class)
              ->resolveActiveWorkspace(auth()->user());
            $agentService = app(CommunicationsAgentService::class);

            return [
              'agents' => $agentService->listForWorkspace($workspace),
              'suggested_extension' => $agentService->suggestExtensionNum(),
              'available_phone_lines' => $agentService->availablePhoneLines($workspace),
            ];
          }, ['agents' => [], 'suggested_extension' => (string) (config('integrations.communications.default_caller_id') ?: '1020'), 'available_phone_lines' => []], $warnings);
          $suggestedExtensionNum = $communicationAgents['suggested_extension'] ?? (string) (config('integrations.communications.default_caller_id') ?: '1020');
          $availablePhoneLines = $communicationAgents['available_phone_lines'] ?? [];
          $communicationAgents = $communicationAgents['agents'] ?? [];
        }

        if ($canConfigure && $channel === 'team') {
          $teamUsers = collect($this->safeDataLoad(fn () => $this->morpheusHub->users(), [], $warnings))
            ->map(fn (array $user) => [
              'id' => $user['id'] ?? null,
              'name' => trim(($user['first_name'] ?? '').' '.($user['last_name'] ?? '')) ?: ($user['email'] ?? 'Morpheus user'),
              'email' => $user['email'] ?? null,
              'type' => $user['type'] ?? 'user',
              'status' => $user['status'] ?? 'active',
              'last_login_time' => $user['last_login_time'] ?? null,
            ])
            ->values()
            ->all();
          $morpheusUsers = $teamUsers;
        }

        if ($request->filled('queue') && $channel === 'queues') {
          $selectedQueueWaiting = $this->safeDataLoad(
            fn () => $this->morpheusHub->queueWaiting((string) $request->get('queue')),
            [],
            $warnings,
          );
        }

        if ($request->filled('conference') && $channel === 'conferences') {
          $selectedConferenceMembers = $this->safeDataLoad(
            fn () => $this->morpheusHub->conferenceMembers((string) $request->get('conference')),
            [],
            $warnings,
          );
        }

        $sidebarChannel = ($fastDialerShell && $callLogs !== []) ? 'calls' : $channel;

        $sidebarItems = $this->buildSidebarItems(
          $sidebarChannel,
          $contacts,
          $callLogs,
          $voiceMails,
          $smsSessions,
          $chatChannels,
          $recordings,
          $teamUsers,
          $morpheusQueues,
          $morpheusConferences,
          $morpheusLeads,
          $morpheusCampaigns,
          $morpheusLists,
          $morpheusExtensions,
          $request,
          $routePrefix,
        );

        $listBaseQuery = $this->listBaseQuery($request, $channel);
        $listPaginated = SimplePaginator::paginate(
          $sidebarItems,
          $request,
          $routePrefix.'communications.index',
          $listBaseQuery,
          'list_page',
          $nextPageToken,
        );
        $sidebarItems = $listPaginated['items'];
        $listPagination = $listPaginated['pagination'];

        if ($panel === 'contact' && $request->filled('contact')) {
          $show = $this->contacts->buildShowPayload(
            (string) $request->get('contact'),
            $filters,
            $callLogs,
            $voiceMails,
            $smsSessions,
          );
          $selectedContact = $show['contact'];
          $timeline = $show['timeline'];
          $contactStats = $show['stats'];
          $smsSession = $show['sms_session'] ?? null;
          if ($show['error']) {
            $errors[] = $show['error'];
          }

          $timelinePaginated = SimplePaginator::paginate(
            $timeline,
            $request,
            $routePrefix.'communications.index',
            $listBaseQuery,
            'panel_page',
          );
          $timeline = $timelinePaginated['items'];
          $panelPagination = $timelinePaginated['pagination'];
        }

        if ($panel === 'sms' && $request->filled('session')) {
          $sessionId = (string) $request->get('session');
          $smsSession = collect($smsSessions)->firstWhere('session_id', $sessionId) ?? [
            'session_id' => $sessionId,
            'label' => 'SMS conversation',
            'session_type' => 'user',
            'owner_phone' => null,
            'other_phone' => null,
          ];
          $messagePayload = $this->data->smsMessages($sessionId, array_merge($filters, [
            'page_token' => $request->get('msg_page_token'),
          ]));
          $smsMessages = collect($messagePayload['messages'])->sortBy('date_time')->values()->all();
          $smsMessagesNextPageToken = $messagePayload['next_page_token'];
        }

        if ($panel === 'chat') {
          $ownerUserId = (string) $request->get('chat_owner');
          $channelId = $request->get('chat_channel');
          $contactEmail = $request->get('chat_contact');
          if ($ownerUserId && ($channelId || $contactEmail)) {
            $threadKey = $channelId
              ? $ownerUserId.':'.$channelId
              : $ownerUserId.':contact:'.$contactEmail;
            $selectedThread = collect($chatChannels)->firstWhere('thread_key', $threadKey) ?? [
              'thread_key' => $threadKey,
              'channel_id' => $channelId,
              'owner_user_id' => $ownerUserId,
              'owner_name' => 'Zoom user',
              'label' => $channelId ? 'Team Chat channel' : ($contactEmail ?? 'Direct message'),
              'thread_type' => $channelId ? 'channel' : 'contact',
            ];
            $messagePayload = $this->data->teamChatMessages($ownerUserId, array_merge($filters, [
              'to_channel' => $channelId,
              'to_contact' => $contactEmail,
              'page_token' => $request->get('msg_page_token'),
            ]));
            $chatMessages = collect($messagePayload['messages'])->sortBy('date_time')->values()->all();
            $chatMessagesNextPageToken = $messagePayload['next_page_token'];
          }
        }

        if ($panel === 'call' && $request->filled('call')) {
          $selectedCall = collect($callLogs)->firstWhere('id', $request->get('call'));
        }

        if ($panel === 'voicemail' && $request->filled('voicemail')) {
          $selectedVoicemail = collect($voiceMails)->first(
            fn ($vm) => ($vm['file_id'] ?? $vm['id'] ?? null) === $request->get('voicemail')
          );
        }

        if ($panel === 'recording' && $request->filled('recording')) {
          $selectedRecording = collect($recordings)->firstWhere('id', $request->get('recording'));
        }

        $channelCounts = $this->buildChannelCounts(
          $contacts,
          $callLogs,
          in_array($channel, ['inbox', 'calls'], true) && $callStats !== [] ? $callStats : $summaryStats,
          in_array($channel, ['inbox', 'voicemail'], true) ? $voiceMails : $summaryVms,
          in_array($channel, ['inbox', 'sms'], true) ? $smsSessions : $summarySms,
          $chatChannels,
          $recordings,
          $morpheusQueues,
          $morpheusConferences,
          $morpheusLeads,
          $morpheusCampaigns,
          $morpheusLists,
          $morpheusExtensions,
        );

        if ($panel === 'sms' && $smsMessages !== []) {
          $smsPaginated = $this->applyPanelPagination(
            $smsMessages,
            $request,
            $routePrefix,
            $channel,
            $smsMessagesNextPageToken,
            'msg_page_token',
          );
          $smsMessages = $smsPaginated['items'];
          $panelPagination ??= $smsPaginated['pagination'];
        }

        if ($panel === 'chat' && $chatMessages !== []) {
          $chatPaginated = $this->applyPanelPagination(
            $chatMessages,
            $request,
            $routePrefix,
            $channel,
            $chatMessagesNextPageToken,
            'msg_page_token',
          );
          $chatMessages = $chatPaginated['items'];
          $panelPagination ??= $chatPaginated['pagination'];
        }

        if ($channel === 'leads') {
          $paginated = $this->applyPanelPagination($morpheusLeads, $request, $routePrefix, $channel);
          $morpheusLeads = $paginated['items'];
          $panelPagination ??= $paginated['pagination'];
        } elseif ($channel === 'campaigns') {
          $paginated = $this->applyPanelPagination($morpheusCampaigns, $request, $routePrefix, $channel);
          $morpheusCampaigns = $paginated['items'];
          $panelPagination ??= $paginated['pagination'];
        } elseif ($channel === 'lists') {
          $paginated = $this->applyPanelPagination($morpheusLists, $request, $routePrefix, $channel);
          $morpheusLists = $paginated['items'];
          $panelPagination ??= $paginated['pagination'];
        } elseif ($channel === 'extensions') {
          $paginated = $this->applyPanelPagination($morpheusExtensions, $request, $routePrefix, $channel);
          $morpheusExtensions = $paginated['items'];
          $panelPagination ??= $paginated['pagination'];
        } elseif ($channel === 'agents') {
          $paginated = $this->applyPanelPagination($communicationAgents, $request, $routePrefix, $channel);
          $communicationAgents = $paginated['items'];
          $panelPagination ??= $paginated['pagination'];
        } elseif ($channel === 'team') {
          $paginated = $this->applyPanelPagination($teamUsers, $request, $routePrefix, $channel);
          $teamUsers = $paginated['items'];
          $panelPagination ??= $paginated['pagination'];
        } elseif ($channel === 'queues') {
          $paginated = $this->applyPanelPagination($morpheusQueues, $request, $routePrefix, $channel);
          $morpheusQueues = $paginated['items'];
          $panelPagination ??= $paginated['pagination'];
        } elseif ($channel === 'conferences') {
          $paginated = $this->applyPanelPagination($morpheusConferences, $request, $routePrefix, $channel);
          $morpheusConferences = $paginated['items'];
          $panelPagination ??= $paginated['pagination'];
        }
      } catch (\Throwable $e) {
        $errors[] = $this->zoom->humanizeError($e->getMessage());
      }
    }

    $dialerCallLogsHasMore = false;
    $dialerImportedLeads = [];
    $dialerImportedLeadsHasMore = false;
    $dialerImportedLeadsTotal = 0;
    $dialerCampaignOptions = [];
    $dialerFileOptions = [];
    $agentTotalDials = 0;

    if ($panel === 'dialer') {
      try {
        // Fast first paint: first 30 local call logs only (more via infinite scroll).
        $callLogs = [];
        $activeCalls = [];
        $dialerCallLogsHasMore = false;
        $pageSize = (int) config('integrations.communications.list_page_size', 30);

        if ($workspace && $user) {
          $agentTotalDials = $this->callHistory->countDialsForUser($workspace, $user);
          $firstPage = $this->paginateDialerCallLogs(
            $filters,
            0,
            $pageSize,
            $routePrefix,
            false,
          );
          $callLogs = $firstPage['logs'] ?? [];
          $dialerCallLogsHasMore = (bool) ($firstPage['has_more'] ?? false);
        }

        if (($hubAccess['canAutoDial'] ?? false) && $workspace) {
          $importedLeadsService = app(DialerImportedLeadsService::class);
          $dialerCampaignOptions = [];
          $tier = (string) ($hubAccess['tier'] ?? '');
          $isAgentQueue = in_array($tier, ['agent', 'team_lead'], true);
          $dialerFileOptions = $isAgentQueue
            ? $importedLeadsService->fileOptions($workspace, (int) ($user->id ?? 0))
            : [];

          if ($isAgentQueue && $user) {
            $leadsPageSize = (int) config('integrations.communications.dialer_leads_page_size', 25);
            $agentPage = $importedLeadsService->paginate($workspace, [
              'assigned_user_id' => (int) $user->id,
              'pool' => 'assigned',
              'workflow_ids' => [],
            ], 0, $leadsPageSize);
            $dialerImportedLeads = $agentPage['leads'] ?? [];
            $dialerImportedLeadsHasMore = (bool) ($agentPage['has_more'] ?? false);
            $dialerImportedLeadsTotal = (int) ($agentPage['total'] ?? count($dialerImportedLeads));
          } else {
            $dialerImportedLeads = [];
            $dialerImportedLeadsHasMore = true;
            $dialerImportedLeadsTotal = 0;
          }
        }
      } catch (\Throwable $e) {
        $warnings[] = $this->zoom->humanizeError($e->getMessage());
      }
    }

    return [
      'channel' => $channel,
      'panel' => $panel,
      'filters' => $filters,
      'routePrefix' => $routePrefix,
      'channels' => $this->access->channelsFor($user, $routePrefix),
      'sidebarItems' => $sidebarItems,
      'contacts' => $contacts,
      'callLogs' => $callLogs,
      'dialerCallLogsHasMore' => $dialerCallLogsHasMore,
      'dialerImportedLeads' => $dialerImportedLeads,
      'dialerImportedLeadsHasMore' => $dialerImportedLeadsHasMore,
      'dialerImportedLeadsTotal' => $dialerImportedLeadsTotal,
      'dialerCampaignOptions' => $dialerCampaignOptions,
      'dialerFileOptions' => $dialerFileOptions,
      'agentTotalDials' => $agentTotalDials,
      'callStats' => $callStats,
      'voiceMails' => $voiceMails,
      'smsSessions' => $smsSessions,
      'chatChannels' => $chatChannels,
      'recordings' => $recordings,
      'teamUsers' => $teamUsers,
      'teamQueues' => $teamQueues,
      'queueWarning' => $queueWarning ?? null,
      'morpheusQueues' => $morpheusQueues,
      'morpheusConferences' => $morpheusConferences,
      'morpheusLeads' => $morpheusLeads,
      'morpheusCampaigns' => $morpheusCampaigns,
      'morpheusLists' => $morpheusLists,
      'morpheusExtensions' => $morpheusExtensions,
      'communicationAgents' => $communicationAgents,
      'suggestedExtensionNum' => $suggestedExtensionNum,
      'availablePhoneLines' => $availablePhoneLines,
      'morpheusUsers' => $morpheusUsers,
      'activeCalls' => $activeCalls,
      'selectedQueueWaiting' => $selectedQueueWaiting,
      'selectedConferenceMembers' => $selectedConferenceMembers,
      'phoneUsers' => $phoneUsers,
      'recentNumbers' => $recentNumbers,
      'nextPageToken' => $nextPageToken,
      'listPagination' => $listPagination,
      'panelPagination' => $panelPagination,
      'selectedContact' => $selectedContact,
      'timeline' => $timeline,
      'contactStats' => $contactStats,
      'smsSession' => $smsSession,
      'smsMessages' => $smsMessages,
      'smsMessagesNextPageToken' => $smsMessagesNextPageToken,
      'selectedThread' => $selectedThread,
      'chatMessages' => $chatMessages,
      'chatMessagesNextPageToken' => $chatMessagesNextPageToken,
      'selectedCall' => $selectedCall,
      'selectedVoicemail' => $selectedVoicemail,
      'selectedRecording' => $selectedRecording,
      'connection' => $connection,
      'connectionDiagnostics' => $panel === 'settings'
        ? $this->zoom->connectionDiagnostics()
        : null,
      'settings' => [
        'accountId' => $this->zoom->accountId(),
        'clientId' => $this->zoom->clientId(),
        'maskedSecret' => $this->zoom->maskedSecret(),
        'webhookSecret' => $this->zoom->webhookSecret(),
        'requiredScopes' => $this->zoom->requiredScopes(),
      ],
      'defaultCallerId' => $this->resolveDefaultCallerId(),
      'prefillNumber' => $this->resolvePrefillDialNumber($request),
      'error' => $errors !== [] ? implode(' ', array_unique($errors)) : null,
      'warnings' => array_values(array_unique($warnings)),
      'clickToCall' => $this->clickToCall,
      'channelCounts' => $channelCounts,
      'hubAccess' => $hubAccess,
    ];
  }

  /**
   * Minimal Communications Hub payload when telephony services fail unexpectedly.
   *
   * @return array<string, mixed>
   */
  public function buildDegraded(Request $request, string $routePrefix, \Throwable $cause): array
  {
    $user = Auth::user();
    $filters = $this->filters($request);
    $hubAccess = $this->access->viewMeta($user, $routePrefix);
    $workspace = $user ? $this->workspaceContext->resolveActiveWorkspace($user) : null;
    $callLogs = $workspace ? $this->callHistory->listForHub($workspace, $filters) : [];
    $extensions = ($user && $workspace)
      ? app(CommunicationsAgentService::class)->dialerExtensionsFast($user, $workspace, $routePrefix)
      : [];
    $breakerMessage = app(\App\Services\Integrations\MorpheusCircuitBreaker::class)->unavailableMessage();

    return [
      'channel' => 'inbox',
      'panel' => 'empty',
      'filters' => $filters,
      'routePrefix' => $routePrefix,
      'channels' => $this->access->channelsFor($user, $routePrefix),
      'sidebarItems' => [],
      'contacts' => [],
      'callLogs' => $callLogs,
      'callStats' => $this->data->callStatsFromLogs($callLogs),
      'voiceMails' => [],
      'smsSessions' => [],
      'chatChannels' => [],
      'recordings' => [],
      'teamUsers' => [],
      'teamQueues' => [],
      'queueWarning' => null,
      'morpheusQueues' => [],
      'morpheusConferences' => [],
      'morpheusLeads' => [],
      'morpheusCampaigns' => [],
      'morpheusLists' => [],
      'morpheusExtensions' => $extensions,
      'communicationAgents' => [],
      'suggestedExtensionNum' => (string) (config('integrations.communications.default_caller_id') ?: '1020'),
      'availablePhoneLines' => [],
      'morpheusUsers' => [],
      'activeCalls' => [],
      'selectedQueueWaiting' => [],
      'selectedConferenceMembers' => [],
      'phoneUsers' => [],
      'recentNumbers' => [],
      'nextPageToken' => null,
      'listPagination' => null,
      'panelPagination' => null,
      'selectedContact' => null,
      'timeline' => [],
      'contactStats' => [],
      'smsSession' => null,
      'smsMessages' => [],
      'smsMessagesNextPageToken' => null,
      'selectedThread' => null,
      'chatMessages' => [],
      'chatMessagesNextPageToken' => null,
      'selectedCall' => null,
      'selectedVoicemail' => null,
      'selectedRecording' => null,
      'connection' => [
        'connected' => false,
        'message' => $breakerMessage,
        'expires_at' => null,
      ],
      'connectionDiagnostics' => null,
      'settings' => [
        'accountId' => $this->zoom->accountId(),
        'clientId' => $this->zoom->clientId(),
        'maskedSecret' => $this->zoom->maskedSecret(),
        'webhookSecret' => $this->zoom->webhookSecret(),
        'requiredScopes' => $this->zoom->requiredScopes(),
      ],
      'defaultCallerId' => $this->resolveDefaultCallerId(),
      'prefillNumber' => $this->resolvePrefillDialNumber($request),
      'error' => null,
      'warnings' => array_values(array_filter([
        $breakerMessage,
        config('app.debug') ? $cause->getMessage() : null,
      ])),
      'clickToCall' => $this->clickToCall,
      'channelCounts' => ['inbox' => 0, 'calls' => count($callLogs)],
      'hubAccess' => $hubAccess,
    ];
  }

  public function resolveChannel(Request $request): string
  {
    return 'inbox';
  }

  public function resolvePanel(Request $request, string $channel): string
  {
      return 'dialer';
  }

  /**
   * @template T
   * @param  callable(): T  $callback
   * @param  T  $default
   * @param  array<int, string>  $warnings
   * @return T
   */
  protected function safeDataLoad(callable $callback, mixed $default, array &$warnings): mixed
  {
    try {
      return $callback();
    } catch (\Throwable $e) {
      $warnings[] = $this->zoom->humanizeError($e->getMessage());

      return $default;
    }
  }

  /**
   * @return array<string, mixed>
   */
  public function filters(Request $request): array
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
      'per_page' => (int) config('integrations.communications.list_page_size', 20),
    ];
  }

  /**
   * @return array<string, mixed>
   */
  protected function listBaseQuery(Request $request, string $channel): array
  {
    return array_filter([
      'channel' => $channel,
      'search' => $request->get('search'),
      'filter' => $request->get('filter'),
      'direction' => $request->get('direction'),
      'status' => $request->get('status'),
      'from' => $request->get('from'),
      'to' => $request->get('to'),
      'panel' => $request->get('panel'),
      'contact' => $request->get('contact'),
      'session' => $request->get('session'),
      'call' => $request->get('call'),
      'voicemail' => $request->get('voicemail'),
      'recording' => $request->get('recording'),
      'chat_owner' => $request->get('chat_owner'),
      'chat_channel' => $request->get('chat_channel'),
      'chat_contact' => $request->get('chat_contact'),
      'queue' => $request->get('queue'),
      'conference' => $request->get('conference'),
    ], fn ($value) => $value !== null && $value !== '');
  }

  /**
   * @return array{items: array<int, mixed>, pagination: array<string, mixed>|null}
   */
  protected function applyPanelPagination(
    array $items,
    Request $request,
    string $routePrefix,
    string $channel,
    ?string $apiNextPageToken = null,
    string $apiPageTokenKey = 'page_token',
  ): array {
    if ($items === []) {
      return ['items' => [], 'pagination' => null];
    }

    $baseQuery = $this->listBaseQuery($request, $channel);
    if ($apiPageTokenKey === 'msg_page_token' && $request->filled('msg_page_token')) {
      $baseQuery['msg_page_token'] = $request->get('msg_page_token');
    }

    $result = SimplePaginator::paginate(
      $items,
      $request,
      $routePrefix.'communications.index',
      $baseQuery,
      'panel_page',
      $apiNextPageToken,
      $apiPageTokenKey,
    );

    return [
      'items' => $result['items'],
      'pagination' => $result['pagination'],
    ];
  }

  /**
   * @param  array<int, array<string, mixed>>  $logs
   * @return array<int, array<string, mixed>>
   */
  protected function filterCallLogs(array $logs, array $filters): array
  {
    $logs = collect($logs);

    if (($filters['direction'] ?? '') !== '') {
      $logs = $logs->where('direction', $filters['direction']);
    }

    if (($filters['filter'] ?? '') === 'recorded') {
      $logs = $logs->filter(
        fn ($log) => ($log['recording'] ?? '') === 'Yes' || ! empty($log['has_recording_media'])
      );
    }

    if (($filters['filter'] ?? '') === 'missed') {
      $logs = $logs->filter(fn ($log) => $this->isMissedCall($log));
    }

    $scopedUserId = (int) ($filters['user_id'] ?? 0);
    $scopedExt = preg_replace('/\D/', '', (string) ($filters['from_extension'] ?? '')) ?: '';
    if ($scopedUserId > 0 || $scopedExt !== '') {
      $logs = $logs->filter(function (array $log) use ($scopedUserId, $scopedExt) {
        $logUserId = (int) ($log['user_id'] ?? data_get($log, 'raw.user_id') ?? 0);
        if ($scopedUserId > 0 && $logUserId === $scopedUserId) {
          return true;
        }

        $logExt = preg_replace(
          '/\D/',
          '',
          (string) ($log['from_extension'] ?? data_get($log, 'raw.from_extension') ?? ''),
        ) ?: '';
        if ($logExt === '' && ! empty($log['from']) && preg_match('/ext\s+(\d+)/i', (string) $log['from'], $m)) {
          $logExt = $m[1];
        }

        if ($scopedExt !== '' && $logExt === $scopedExt) {
          return true;
        }

        return false;
      });
    }

    return $logs->values()->all();
  }

  /**
   * @param  array<string, mixed>  $log
   */
  protected function isMissedCall(array $log): bool
  {
    $result = strtolower((string) ($log['result'] ?? ''));

    return str_contains($result, 'miss') || str_contains($result, 'no answer');
  }

  /**
   * @param  array<int, array<string, mixed>>  $liveLogs
   * @param  array<string, mixed>  $filters
   * @param  array<int, string>  $warnings
   * @return array<int, array<string, mixed>>
   */
  protected function mergeLocalCallHistory(array $liveLogs, array $filters, array &$warnings): array
  {
    $user = Auth::user();
    if (! $user) {
      return $liveLogs;
    }

    $workspace = $this->workspaceContext->resolveActiveWorkspace($user);
    if (! $workspace) {
      return $liveLogs;
    }

    $history = $this->callHistory->listForHub($workspace, $filters);
    if ($history === [] && $liveLogs === []) {
      return $liveLogs;
    }

    if ($history !== []) {
      $warnings[] = 'Call history includes Morpheus CDR and hub-logged dials when available.';
    }

    return $this->callHistory->mergeLiveAndHistory($liveLogs, $history);
  }

  /**
   * @param  array<int, array<string, mixed>>  $voiceMails
   * @return array<int, array<string, mixed>>
   */
  protected function filterVoiceMails(array $voiceMails, array $filters): array
  {
    $status = $filters['status'] ?? 'all';
    if ($status === 'all' || $status === null || $status === '') {
      return $voiceMails;
    }

    return collect($voiceMails)
      ->filter(fn (array $vm) => ($vm['status'] ?? '') === $status)
      ->values()
      ->all();
  }

  /**
   * @param  array<int, array<string, mixed>>  $contacts
   * @param  array<int, array<string, mixed>>  $callLogs
   * @param  array<string, int|float>  $summaryStats
   * @param  array<int, array<string, mixed>>  $summaryVms
   * @param  array<int, array<string, mixed>>  $summarySms
   * @param  array<int, array<string, mixed>>  $chatChannels
   * @param  array<int, array<string, mixed>>  $recordings
   * @return array<string, int>
   */
  protected function buildChannelCounts(
    array $contacts,
    array $callLogs,
    array $summaryStats,
    array $summaryVms,
    array $summarySms,
    array $chatChannels,
    array $recordings,
    array $queues = [],
    array $conferences = [],
    array $leads = [],
    array $campaigns = [],
    array $lists = [],
    array $extensions = [],
  ): array {
    return [
      'inbox' => count($contacts),
      'calls' => count($callLogs) ?: (int) ($summaryStats['total'] ?? 0),
      'calls_total' => (int) ($summaryStats['total'] ?? 0),
      'calls_missed' => (int) ($summaryStats['missed'] ?? 0),
      'queues' => count($queues),
      'conferences' => count($conferences),
      'leads' => count($leads),
      'campaigns' => count($campaigns),
      'lists' => count($lists),
      'extensions' => count($extensions),
      'sms' => count($summarySms),
      'voicemail' => count($summaryVms),
      'voicemail_unread' => collect($summaryVms)->where('status', 'unread')->count(),
      'chat' => count($chatChannels),
      'recordings' => count($recordings),
      'team' => 0,
    ];
  }

  /**
   * @return array<int, array<string, mixed>>
   */
  protected function buildSidebarItems(
    string $channel,
    array $contacts,
    array $callLogs,
    array $voiceMails,
    array $smsSessions,
    array $chatChannels,
    array $recordings,
    array $teamUsers,
    array $queues,
    array $conferences,
    array $leads,
    array $campaigns,
    array $lists,
    array $extensions,
    Request $request,
    string $routePrefix,
  ): array {
    $baseQuery = array_filter([
      'channel' => $channel,
      'search' => $request->get('search'),
      'filter' => $request->get('filter'),
      'direction' => $request->get('direction'),
      'status' => $request->get('status'),
      'from' => $request->get('from'),
      'to' => $request->get('to'),
    ]);

    return match ($channel) {
      'calls' => collect($callLogs)->map(function (array $log) use ($baseQuery, $routePrefix, $request) {
        $id = $log['id'] ?? null;

        return [
          'key' => 'call:'.$id,
          'kind' => 'call',
          'label' => ($log['from'] ?? '—').' → '.($log['to'] ?? '—'),
          'subtitle' => ($log['result'] ?? '—').' · '.($log['duration'] ?? 0).'s',
          'time' => $log['start_time'] ?? null,
          'badge' => $log['direction'] ?? null,
          'avatar' => strtoupper(substr($log['direction'] ?? 'C', 0, 1)),
          'active' => $request->get('call') === $id,
          'url' => route($routePrefix.'communications.index', array_merge($baseQuery, ['call' => $id])),
        ];
      })->values()->all(),

      'sms' => collect($smsSessions)->map(function (array $session) use ($baseQuery, $routePrefix, $request) {
        $id = $session['session_id'] ?? null;

        return [
          'key' => 'sms:'.$id,
          'kind' => 'sms',
          'label' => $session['label'] ?? 'SMS',
          'subtitle' => trim(($session['owner_phone'] ?? '—').' ↔ '.($session['other_phone'] ?? '—')),
          'time' => $session['last_access_time'] ?? null,
          'badge' => 'sms',
          'avatar' => 'SMS',
          'active' => $request->get('session') === $id,
          'url' => route($routePrefix.'communications.index', array_merge($baseQuery, ['session' => $id])),
        ];
      })->values()->all(),

      'voicemail' => collect($voiceMails)->map(function (array $vm) use ($baseQuery, $routePrefix, $request) {
        $id = $vm['file_id'] ?? $vm['id'] ?? null;

        return [
          'key' => 'vm:'.$id,
          'kind' => 'voicemail',
          'label' => $vm['caller'] ?? 'Voicemail',
          'subtitle' => $vm['caller_number'] ?? ($vm['callee'] ?? '—'),
          'time' => $vm['date_time'] ?? null,
          'badge' => $vm['status'] ?? null,
          'avatar' => 'VM',
          'active' => $request->get('voicemail') === $id,
          'url' => route($routePrefix.'communications.index', array_merge($baseQuery, ['voicemail' => $id])),
        ];
      })->values()->all(),

      'chat' => collect($chatChannels)->map(function (array $channelRow) use ($baseQuery, $routePrefix, $request) {
        $params = array_merge($baseQuery, array_filter([
          'chat_owner' => $channelRow['owner_user_id'] ?? null,
          'chat_channel' => $channelRow['channel_id'] ?? null,
          'chat_contact' => ($channelRow['thread_type'] ?? '') === 'contact' ? ($channelRow['contact_email'] ?? null) : null,
        ]));

        return [
          'key' => $channelRow['thread_key'] ?? null,
          'kind' => 'chat',
          'label' => $channelRow['label'] ?? 'Chat',
          'subtitle' => $channelRow['owner_name'] ?? '—',
          'time' => $channelRow['last_message_sent_time'] ?? null,
          'badge' => $channelRow['type'] ?? 'channel',
          'avatar' => '#',
          'active' => $request->get('chat_channel') === ($channelRow['channel_id'] ?? null)
            && $request->get('chat_owner') === ($channelRow['owner_user_id'] ?? null),
          'url' => route($routePrefix.'communications.index', $params),
        ];
      })->values()->all(),

      'recordings' => collect($recordings)->map(function (array $rec) use ($baseQuery, $routePrefix, $request) {
        $id = $rec['id'] ?? null;

        return [
          'key' => 'rec:'.$id,
          'kind' => 'recording',
          'label' => $rec['topic'] ?? 'Recording',
          'subtitle' => ($rec['source'] ?? 'phone').' · '.($rec['host'] ?? '—'),
          'time' => $rec['start_time'] ?? null,
          'badge' => $rec['file_type'] ?? null,
          'avatar' => 'R',
          'active' => $request->get('recording') === $id,
          'url' => route($routePrefix.'communications.index', array_merge($baseQuery, ['recording' => $id])),
        ];
      })->values()->all(),

      'team' => collect($teamUsers)->map(function (array $user) use ($baseQuery, $routePrefix) {
        return [
          'key' => 'team:'.$user['id'],
          'kind' => 'team',
          'label' => $user['name'] ?? 'User',
          'subtitle' => $user['email'] ?? '—',
          'time' => $user['last_login_time'] ?? null,
          'badge' => $user['status'] ?? null,
          'avatar' => strtoupper(substr($user['name'] ?? 'U', 0, 2)),
          'active' => false,
          'url' => route($routePrefix.'communications.index', array_merge($baseQuery, ['channel' => 'team'])),
        ];
      })->values()->all(),

      'queues' => collect($queues)->map(function (array $queue) use ($baseQuery, $routePrefix, $request) {
        $id = $queue['id'] ?? null;

        return [
          'key' => 'queue:'.$id,
          'kind' => 'queue',
          'label' => $queue['name'] ?? 'Queue',
          'subtitle' => ($queue['waiting'] ?? 0).' waiting · '.($queue['longest_wait_sec'] ?? 0).'s',
          'time' => null,
          'badge' => $queue['status'] ?? null,
          'avatar' => 'Q',
          'active' => $request->get('queue') === $id,
          'url' => route($routePrefix.'communications.index', array_merge($baseQuery, ['queue' => $id])),
        ];
      })->values()->all(),

      'conferences' => collect($conferences)->map(function (array $room) use ($baseQuery, $routePrefix, $request) {
        $id = $room['id'] ?? null;

        return [
          'key' => 'conf:'.$id,
          'kind' => 'conference',
          'label' => $room['name'] ?? 'Conference',
          'subtitle' => 'Ext '.($room['extension_num'] ?? '—'),
          'time' => null,
          'badge' => ($room['enabled'] ?? true) ? 'active' : 'disabled',
          'avatar' => 'C',
          'active' => $request->get('conference') === $id,
          'url' => route($routePrefix.'communications.index', array_merge($baseQuery, ['conference' => $id])),
        ];
      })->values()->all(),

      'leads' => collect($leads)->map(function (array $lead) use ($baseQuery, $routePrefix) {
        $id = $lead['id'] ?? null;
        $name = trim(($lead['first_name'] ?? '').' '.($lead['last_name'] ?? '')) ?: ($lead['phone_number'] ?? 'Lead');

        return [
          'key' => 'lead:'.$id,
          'kind' => 'lead',
          'label' => $name,
          'subtitle' => ($lead['phone_number'] ?? '—').' · '.($lead['status'] ?? '—'),
          'time' => $lead['updated_at'] ?? null,
          'badge' => $lead['disposition'] ?? null,
          'avatar' => 'L',
          'active' => false,
          'url' => route($routePrefix.'communications.index', array_merge($baseQuery, ['channel' => 'leads'])),
        ];
      })->values()->all(),

      'campaigns' => collect($campaigns)->map(function (array $campaign) use ($baseQuery, $routePrefix) {
        $id = $campaign['id'] ?? null;

        return [
          'key' => 'campaign:'.$id,
          'kind' => 'campaign',
          'label' => $campaign['name'] ?? 'Campaign',
          'subtitle' => ($campaign['dial_mode'] ?? '—').' · '.($campaign['status'] ?? '—'),
          'time' => null,
          'badge' => $campaign['status'] ?? null,
          'avatar' => 'P',
          'active' => false,
          'url' => route($routePrefix.'communications.index', array_merge($baseQuery, ['channel' => 'campaigns'])),
        ];
      })->values()->all(),

      'lists' => collect($lists)->map(function (array $list) use ($baseQuery, $routePrefix) {
        $id = $list['id'] ?? null;

        return [
          'key' => 'list:'.$id,
          'kind' => 'list',
          'label' => $list['name'] ?? 'List',
          'subtitle' => $list['status'] ?? '—',
          'time' => null,
          'badge' => $list['status'] ?? null,
          'avatar' => '#',
          'active' => false,
          'url' => route($routePrefix.'communications.index', array_merge($baseQuery, ['channel' => 'lists'])),
        ];
      })->values()->all(),

      'extensions' => collect($extensions)->map(function (array $ext) use ($baseQuery, $routePrefix) {
        $id = $ext['id'] ?? $ext['extension_num'] ?? null;

        return [
          'key' => 'ext:'.$id,
          'kind' => 'extension',
          'label' => $ext['caller_id_name'] ?? ('Ext '.$ext['extension_num']),
          'subtitle' => $ext['extension_num'] ?? '—',
          'time' => null,
          'badge' => $ext['status'] ?? null,
          'avatar' => 'E',
          'active' => false,
          'url' => route($routePrefix.'communications.index', array_merge($baseQuery, ['channel' => 'extensions'])),
        ];
      })->values()->all(),

      default => collect($contacts)->map(function (array $contact) use ($baseQuery, $routePrefix, $request) {
        $key = $contact['contact_key'] ?? null;

        return [
          'key' => $key,
          'kind' => 'contact',
          'label' => $contact['name'] ?? 'Contact',
          'subtitle' => $contact['phone'] ?? $contact['email'] ?? 'No phone or email',
          'time' => $contact['last_activity_at'] ?? null,
          'badge' => $contact['last_activity_type'] ?? $contact['tag'] ?? null,
          'avatar' => strtoupper(substr($contact['name'] ?? '?', 0, 2)),
          'active' => $request->get('contact') === $key,
          'url' => route($routePrefix.'communications.index', array_merge($baseQuery, ['contact' => $key])),
        ];
      })->values()->all(),
    };
  }

  protected function resolveDefaultCallerId(): ?string
  {
    if (! auth()->check()) {
      return config('integrations.communications.default_caller_id');
    }

    return app(CommunicationsAgentService::class)->extensionForUser(auth()->user())
      ?? config('integrations.communications.default_caller_id');
  }

  protected function resolvePrefillDialNumber(Request $request): ?string
  {
    $fromRequest = $request->get('number');
    if (is_string($fromRequest) && trim($fromRequest) !== '') {
      return trim($fromRequest);
    }

    return null;
  }

  /**
   * Dialer call logs ignore URL date filters and use a fixed lookback window.
   *
   * @param  array<string, mixed>  $filters
   * @return array<string, mixed>
   */
  public function dialerCallLogFilters(array $filters): array
  {
    $days = (int) config('integrations.communications.dialer_log_days', 90);

    return array_merge($filters, [
      'from' => now()->subDays($days)->toDateString(),
      'to' => now()->toDateString(),
    ]);
  }

  /**
   * Agents only see their own calls; team leads see their team; admins/supervisors/QA see all.
   *
   * @param  array<string, mixed>  $filters
   * @return array<string, mixed>
   */
  public function applyCallLogActorScope(array $filters, $user, string $routePrefix): array
  {
    if (! $user) {
      return $filters;
    }

    $tier = $this->access->tierFor($user, $routePrefix);

    if ($tier === 'agent') {
      $filters['user_id'] = (int) $user->id;
      $filters['actor_scoped'] = true;

      $extension = app(CommunicationsAgentService::class)->extensionForUser($user);
      if (filled($extension)) {
        $digits = preg_replace('/\D/', '', (string) $extension) ?: (string) $extension;
        if ($digits !== '') {
          $filters['from_extension'] = $digits;
        }
      }

      return $filters;
    }

    if ($tier === 'team_lead') {
      $workspace = $this->workspaceContext->resolveActiveWorkspace($user);
      if ($workspace) {
        $filters['user_ids'] = app(CallNotesHistoryService::class)
          ->dialerAgents($workspace, $user, 'team_lead')
          ->pluck('id')
          ->map(fn ($id) => (int) $id)
          ->all();
        $filters['actor_scoped'] = true;
        unset($filters['user_id']);
      }

      return $filters;
    }

    return $filters;
  }

  /**
   * Limit dialer recordings to agent or team-lead callers when requested.
   *
   * @param  array<string, mixed>  $filters
   * @return array<string, mixed>
   */
  public function applyRecordingRoleScope(array $filters, $workspace): array
  {
    $role = strtolower(trim((string) ($filters['recording_role'] ?? '')));
    if (! in_array($role, ['agent', 'team_lead'], true) || ! $workspace) {
      return $filters;
    }

    $teamLeadRoles = ['appointment_setter_team_lead', 'closers_team_lead'];
    $query = \Illuminate\Support\Facades\DB::table('workspace_user')
      ->where('workspace_id', $workspace->id);

    if ($role === 'team_lead') {
      $query->whereIn('role', $teamLeadRoles);
    } else {
      $query->whereNotIn('role', array_merge($teamLeadRoles, ['super_admin', 'admin', 'manager', 'closers_qa']));
    }

    $filters['user_ids'] = $query->pluck('user_id')->map(fn ($id) => (int) $id)->all();
    unset($filters['user_id'], $filters['from_extension']);

    return $filters;
  }

  /**
   * @param  array<int, array<string, mixed>>  $logs
   * @return array<int, array<string, mixed>>
   */
  public function enrichDialerLogsWithCallerRoles(array $logs, $workspace): array
  {
    if ($logs === [] || ! $workspace) {
      return $logs;
    }

    $userIds = collect($logs)
      ->map(fn (array $log) => (int) ($log['user_id'] ?? data_get($log, 'raw.user_id') ?? 0))
      ->filter(fn (int $id) => $id > 0)
      ->unique()
      ->values()
      ->all();

    if ($userIds === []) {
      return $logs;
    }

    $rolesByUser = \Illuminate\Support\Facades\DB::table('workspace_user')
      ->where('workspace_id', $workspace->id)
      ->whereIn('user_id', $userIds)
      ->pluck('role', 'user_id');

    $teamLeadRoles = ['appointment_setter_team_lead', 'closers_team_lead'];

    return array_map(function (array $log) use ($rolesByUser, $teamLeadRoles) {
      $userId = (int) ($log['user_id'] ?? data_get($log, 'raw.user_id') ?? 0);
      $workspaceRole = $userId > 0 ? (string) ($rolesByUser[$userId] ?? '') : '';
      $group = in_array($workspaceRole, $teamLeadRoles, true) ? 'team_lead' : 'agent';

      $log['agent_role'] = $workspaceRole !== '' ? $workspaceRole : null;
      $log['agent_role_group'] = $group;
      $log['agent_role_label'] = $group === 'team_lead' ? 'Team lead' : 'Agent';

      return $log;
    }, $logs);
  }

  /**
   * @return array<int, array<string, mixed>>
   */
  public function resolveDialerCallLogs(array $filters, bool $mergeRemote = true, ?string $routePrefix = null): array
  {
    $filters = $this->dialerCallLogFilters($filters);
    $routePrefix = $routePrefix ?? (request()->is('admin*') ? 'admin.' : 'portal.');
    $user = Auth::user();
    $filters = $this->applyCallLogActorScope($filters, $user, $routePrefix);

    if (! $user) {
      return [];
    }

    $workspace = $this->workspaceContext->resolveActiveWorkspace($user);
    if (! $workspace) {
      return [];
    }

    $filters = $this->applyRecordingRoleScope($filters, $workspace);

    $logs = $this->callHistory->listForHub($workspace, $filters);
    $logs = $this->enrichDialerLogsWithCallerRoles($logs, $workspace);

    // Agents stay on local history so account-wide Morpheus CDR cannot leak other agents' calls.
    $allowRemote = $mergeRemote
      && $this->zoom->isConfigured()
      && empty($filters['actor_scoped'])
      && empty($filters['recording_role']);

    if (! $allowRemote) {
      return $this->filterCallLogs($logs, $filters);
    }

    $remote = [];
    $pageToken = null;
    $pages = 0;

    do {
      try {
        $payload = $this->data->callLogs(
          array_merge($filters, ['page_token' => $pageToken]),
          1,
          false,
        );
      } catch (\Throwable) {
        break;
      }

      foreach ($payload['logs'] ?? [] as $row) {
        $remote[] = $row;
      }

      $pageToken = $payload['next_page_token'] ?? null;
      $pages++;
    } while ($pageToken && $pages < 3);

    return $this->enrichDialerLogsWithCallerRoles(
      $this->filterCallLogs(
        $this->callHistory->mergeLiveAndHistory($remote, $logs),
        $filters,
      ),
      $workspace,
    );
  }

  /**
   * @return array{logs: array<int, array<string, mixed>>, next_offset: int, has_more: bool}
   */
  public function paginateDialerCallLogs(
    array $filters,
    int $offset,
    int $limit,
    string $routePrefix,
    bool $mergeRemote = true,
  ): array {
    $filters = $this->applyCallLogActorScope($filters, Auth::user(), $routePrefix);
    $recordingsOnly = (bool) ($filters['recordings_only'] ?? false);
    $recordingRole = strtolower(trim((string) ($filters['recording_role'] ?? '')));
    $limit = max(1, min(50, $limit));
    $offset = max(0, $offset);
    $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());

    if (! $workspace) {
      return ['logs' => [], 'next_offset' => $offset, 'has_more' => false];
    }

    $scoped = $this->applyRecordingRoleScope($filters, $workspace);

    // Fast path: local DB page only (no full-list cache / remote merge).
    // Recordings tab may still need enrichment, but still page from local.
    $page = $this->callHistory->pageForHub($workspace, $scoped, $offset, $limit);
    $slice = $page['logs'];
    $hasMore = $page['has_more'];

    $slice = $this->data->enrichCallLogsWithRecordings($slice, $filters);

    if ($recordingsOnly) {
      $slice = array_values(array_filter(
        $slice,
        fn (array $log) => ! empty($log['has_recording_media']) && ! empty($log['recording_id']),
      ));
    }

    $nextOffset = $offset + count($slice);
    $callNotesByRef = $this->callHistory->mapCallNotesForRefs($workspace, $slice);
    $phones = array_map(
      fn (array $log) => CommunicationsLeadLookupService::callbackPhoneFromLog($log),
      $slice,
    );
    $leadLabelsByPhone = $this->leadLookup->mapLabelsForPhones($workspace, $phones);

    return [
      'logs' => array_map(
        fn (array $log) => $this->formatDialerCallLogForApi($log, $routePrefix, [], $callNotesByRef, $leadLabelsByPhone),
        $slice,
      ),
      'next_offset' => $nextOffset,
      'has_more' => $hasMore,
    ];
  }

  public static function bumpDialerLogsCacheVersion(?int $userId = null): void
  {
    $userId = $userId ?? Auth::id();
    if (! $userId) {
      return;
    }

    $key = 'dialer_logs_ver.'.$userId;
    Cache::put($key, ((int) Cache::get($key, 0)) + 1, 86400);
  }

  /**
   * @param  array<int, array<string, mixed>>  $logs
   * @return array<int, array<string, mixed>>
   */
  public function enrichDialerCallLogsWithNotes(Workspace $workspace, array $logs): array
  {
    $notesByRef = $this->callHistory->mapCallNotesForRefs($workspace, $logs);
    $phones = array_values(array_filter(array_map(
      fn (array $log) => CommunicationsLeadLookupService::callbackPhoneFromLog($log),
      $logs,
    )));
    $phoneBodies = $this->phoneNotes->mapBodiesForPhones($workspace, $phones);

    return array_map(function (array $log) use ($notesByRef, $phoneBodies) {
      $callNote = $this->callHistory->resolveCallNoteForHubLog($log, $notesByRef);
      $phone = CommunicationsLeadLookupService::callbackPhoneFromLog($log);
      $phoneKey = $phone ? $this->phoneNotes->normalizePhoneKey($phone) : null;
      $phoneNote = ($phoneKey && isset($phoneBodies[$phoneKey]))
        ? trim((string) $phoneBodies[$phoneKey])
        : '';

      // Phone-level notes are shared across numbers — never show them as this call's comment.
      if ($callNote !== '' && $phoneNote !== '' && strcasecmp($callNote, $phoneNote) === 0) {
        $ref = (string) ($log['id'] ?? '');
        $ownedByThisCall = $ref !== '' && array_key_exists($ref, $notesByRef) && trim((string) $notesByRef[$ref]) === $callNote;
        if (! $ownedByThisCall) {
          $callNote = '';
        }
      }

      $log['phone_note'] = '';
      $log['call_note'] = $callNote;
      $log['note'] = $callNote;
      $log['has_notes'] = $callNote !== '';

      return $log;
    }, $logs);
  }

  /**
   * @param  array<int, array<string, mixed>>  $logs
   * @return array<int, array<string, mixed>>
   */
  public function enrichDialerCallLogsWithLeadLabels(Workspace $workspace, array $logs): array
  {
    $phones = array_map(
      fn (array $log) => CommunicationsLeadLookupService::callbackPhoneFromLog($log),
      $logs,
    );
    $labels = $this->leadLookup->mapLabelsForPhones($workspace, $phones);

    return array_map(function (array $log) use ($labels) {
      $phone = CommunicationsLeadLookupService::callbackPhoneFromLog($log);
      $key = $phone ? $this->phoneNotes->normalizePhoneKey($phone) : null;
      $label = ($key && isset($labels[$key])) ? $labels[$key] : null;

      $log['lead_name'] = $label['name'] ?? null;
      $log['lead_contact'] = $label['contact'] ?? null;
      $log['lead_id'] = $label['lead_id'] ?? null;
      $log['lead_file_name'] = $label['file_name'] ?? null;
      $log['phone_display'] = CommunicationsLeadLookupService::formatPhoneDisplay($phone);

      return $log;
    }, $logs);
  }

  /**
   * @param  array<string, mixed>  $log
   * @param  array<string, string>  $phoneBodies
   * @return array<string, mixed>
   */
  public function formatDialerCallLogForApi(
    array $log,
    string $routePrefix,
    array $phoneBodies = [],
    array $callNotesByRef = [],
    array $leadLabelsByPhone = [],
  ): array {
    $callbackPhone = CommunicationsLeadLookupService::callbackPhoneFromLog($log);

    $logExtension = $log['from_extension']
      ?? data_get($log, 'raw.from_extension')
      ?? null;

    if (! $logExtension && ! empty($log['from']) && preg_match('/ext\s+(\d+)/i', (string) $log['from'], $matches)) {
      $logExtension = $matches[1];
    }

    $hasRecording = ! empty($log['has_recording_media']) && ! empty($log['recording_id']);
    $recordingStatus = (string) ($log['recording_status'] ?? ($hasRecording ? 'ready' : 'none'));
    $recordingSource = $log['recording_source'] ?? 'morpheus';
    $callRef = $log['call_reference_id'] ?? $log['id'] ?? '';
    $playUrl = $hasRecording
      ? route($routePrefix.'communications.zoom.recordings.media', [
        'recordingId' => $log['recording_id'],
        'source' => $recordingSource,
        'action' => 'play',
        'call_ref' => $callRef,
      ])
      : '';
    $downloadUrl = $hasRecording
      ? route($routePrefix.'communications.zoom.recordings.media', [
        'recordingId' => $log['recording_id'],
        'source' => $recordingSource,
        'action' => 'download',
        'call_ref' => $callRef,
      ])
      : '';

    $startTime = $log['start_time'] ?? null;
    $durationSeconds = (int) (
      $log['duration']
      ?? data_get($log, 'raw.billsec')
      ?? data_get($log, 'raw.duration_sec')
      ?? data_get($log, 'raw.duration')
      ?? 0
    );

    $callLogRef = (string) ($log['id'] ?? '');
    $callNote = $this->callHistory->resolveCallNoteForHubLog($log, $callNotesByRef);
    $localCallLogId = null;
    if (str_starts_with($callLogRef, 'local:')) {
      $localCallLogId = (int) substr($callLogRef, 6);
    }

    $phoneKey = $this->phoneNotes->normalizePhoneKey($callbackPhone);
    $phoneNote = $phoneKey ? trim((string) ($phoneBodies[$phoneKey] ?? '')) : '';
    $leadLabel = ($phoneKey && isset($leadLabelsByPhone[$phoneKey])) ? $leadLabelsByPhone[$phoneKey] : null;
    $leadName = filled($log['lead_name'] ?? null)
      ? (string) $log['lead_name']
      : (string) ($leadLabel['name'] ?? '');
    $leadContact = filled($log['lead_contact'] ?? null)
      ? (string) $log['lead_contact']
      : (string) ($leadLabel['contact'] ?? '');
    $leadFileName = filled($log['lead_file_name'] ?? null)
      ? (string) $log['lead_file_name']
      : (string) ($leadLabel['file_name'] ?? '');
    $phoneDisplay = filled($log['phone_display'] ?? null)
      ? (string) $log['phone_display']
      : (CommunicationsLeadLookupService::formatPhoneDisplay($callbackPhone) ?? $callbackPhone);

    return [
      'id' => $log['id'] ?? null,
      'call_log_ref' => $callLogRef !== '' ? $callLogRef : null,
      'local_call_log_id' => $localCallLogId,
      'direction' => $log['direction'] ?? 'call',
      'extension' => $logExtension,
      'user_id' => $log['user_id'] ?? data_get($log, 'raw.user_id'),
      'agent_name' => $log['agent_name'] ?? data_get($log, 'raw.user.name') ?? null,
      'agent_role_group' => $log['agent_role_group'] ?? null,
      'agent_role_label' => $log['agent_role_label'] ?? null,
      'phone' => $callbackPhone,
      'phone_display' => $phoneDisplay,
      'lead_name' => $leadName !== '' ? $leadName : null,
      'lead_contact' => $leadContact !== '' ? $leadContact : null,
      'lead_file_name' => $leadFileName !== '' ? $leadFileName : null,
      'lead_id' => $log['lead_id'] ?? ($leadLabel['lead_id'] ?? null),
      'result' => $log['result'] ?? '—',
      'disposition' => $this->resolveDialerDispositionLabel($log),
      'duration_seconds' => $durationSeconds,
      'duration_label' => self::formatDialerCallDuration($durationSeconds),
      'time_ago' => filled($startTime) ? \Carbon\Carbon::parse($startTime)->diffForHumans(short: true) : '—',
      'time_label' => filled($startTime) ? \Carbon\Carbon::parse($startTime)->format('M j, Y g:i A') : '—',
      'has_recording' => $hasRecording,
      'recording_status' => $recordingStatus,
      'play_url' => $playUrl,
      'download_url' => $downloadUrl,
      'call_note' => $callNote,
      'phone_note' => $phoneNote,
      'in_call_notes' => trim((string) ($log['in_call_notes'] ?? data_get($log, 'raw.meta.in_call_notes') ?? '')),
      'has_notes' => $callNote !== '' || trim((string) ($log['in_call_notes'] ?? data_get($log, 'raw.meta.in_call_notes') ?? '')) !== '',
    ];
  }

  public static function formatDialerCallDuration(int $seconds): string
  {
    if ($seconds <= 0) {
      return '0s';
    }

    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $remaining = $seconds % 60;

    if ($hours > 0) {
      return sprintf('%dh %dm %ds', $hours, $minutes, $remaining);
    }

    if ($minutes > 0) {
      return sprintf('%dm %ds', $minutes, $remaining);
    }

    return sprintf('%ds', $remaining);
  }

  /**
   * Real agent disposition only — never fall back to call result/status (connected, busy, …).
   *
   * @param  array<string, mixed>  $log
   */
  protected function resolveDialerDispositionLabel(array $log): ?string
  {
    $candidates = [
      $log['disposition'] ?? null,
      data_get($log, 'raw.disposition'),
      data_get($log, 'raw.meta.disposition'),
      data_get($log, 'meta.disposition'),
    ];

    // Hyphenated CDR statuses only — keep agent disposition "No Answer" visible.
    $statusLike = [
      '', '—', '-', 'completed', 'initiated', 'connected', 'answered',
      'no-answer', 'busy', 'failed', 'missed', 'unknown',
      'hangup', 'canceled', 'cancelled', 'ringing', 'bridging', 'active', 'talking', 'ended',
    ];

    foreach ($candidates as $candidate) {
      $value = trim((string) ($candidate ?? ''));
      if ($value === '') {
        continue;
      }
      if (in_array(strtolower($value), $statusLike, true)) {
        continue;
      }

      return $value;
    }

    return null;
  }
}
