<?php

namespace App\Services\Communications;

use App\Services\Integrations\ZoomApiService;
use Illuminate\Http\Request;

class CommunicationsInboxService
{
  public const CHANNELS = [
    'inbox' => ['label' => 'Inbox', 'icon' => 'inbox'],
    'calls' => ['label' => 'Calls', 'icon' => 'phone'],
    'sms' => ['label' => 'SMS', 'icon' => 'sms'],
    'voicemail' => ['label' => 'Voicemail', 'icon' => 'voicemail'],
    'chat' => ['label' => 'Chat', 'icon' => 'chat'],
    'recordings' => ['label' => 'Recordings', 'icon' => 'recording'],
    'team' => ['label' => 'Team', 'icon' => 'team'],
  ];

  public function __construct(
    protected ZoomApiService $zoom,
    protected CommunicationsDataService $data,
    protected ZoomContactService $contacts,
    protected ZoomClickToCallService $clickToCall,
  ) {}

  /**
   * @return array<string, mixed>
   */
  public function build(Request $request, string $routePrefix): array
  {
    $filters = $this->filters($request);
    $channel = $this->resolveChannel($request);
    $panel = $this->resolvePanel($request, $channel);
    $errors = [];
    $warnings = [];

    $connection = $this->zoom->isConfigured()
      ? $this->zoom->connectionStatus()
      : ['connected' => false, 'message' => 'Zoom is not configured.', 'expires_at' => null];

    if (! $this->zoom->isConfigured()) {
      $errors[] = 'Zoom is not configured.';
    }

    if ($panel === 'settings') {
      return [
        'channel' => $channel,
        'panel' => $panel,
        'filters' => $filters,
        'routePrefix' => $routePrefix,
        'channels' => self::CHANNELS,
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
        'defaultCallerId' => config('integrations.communications.default_caller_id'),
        'prefillNumber' => $request->get('number'),
        'error' => $errors !== [] ? implode(' ', array_unique($errors)) : null,
        'warnings' => [],
        'clickToCall' => $this->clickToCall,
        'channelCounts' => [],
      ];
    }

    $channelCounts = [];
    $sidebarItems = [];
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

    if ($this->zoom->isConfigured()) {
      try {
        $phonePayload = $this->data->phoneUsers($filters);
        $phoneUsers = $phonePayload['users'];
        if ($phonePayload['warning'] ?? null) {
          $warnings[] = $phonePayload['warning'];
        }

        $loadSummary = in_array($channel, ['inbox', 'calls'], true);
        $loadDialerExtras = $loadSummary || $panel === 'dialer';

        if ($loadDialerExtras) {
          try {
            $recentNumbers = $this->data->recentDialNumbers($filters);
          } catch (\Throwable $e) {
            $warnings[] = $this->zoom->humanizeError($e->getMessage());
          }
        }

        if ($loadSummary) {
          $summaryCalls = $this->safeDataLoad(
            fn () => $this->data->callLogs($filters, 1),
            ['logs' => [], 'next_page_token' => null, 'warning' => null],
            $warnings,
          );
          if ($summaryCalls['warning'] ?? null) {
            $warnings[] = $summaryCalls['warning'];
          }
          $summaryStats = $this->data->callStatsFromLogs($summaryCalls['logs']);
          $summaryVmPayload = $this->safeDataLoad(
            fn () => $this->data->voiceMails($filters),
            ['voice_mails' => [], 'warning' => null],
            $warnings,
          );
          $summaryVms = $summaryVmPayload['voice_mails'];
          if ($summaryVmPayload['warning'] ?? null) {
            $warnings[] = $summaryVmPayload['warning'];
          }
          $summarySmsPayload = $this->safeDataLoad(
            fn () => $this->data->smsSessions($filters),
            ['sessions' => [], 'warning' => null],
            $warnings,
          );
          $summarySms = $summarySmsPayload['sessions'];
          if ($summarySmsPayload['warning'] ?? null) {
            $warnings[] = $summarySmsPayload['warning'];
          }
        } else {
          $summaryStats = [];
          $summaryVms = [];
          $summarySms = [];
        }

        if (in_array($channel, ['inbox', 'calls'], true)) {
          $callPayload = $this->safeDataLoad(
            fn () => $this->data->callLogs($filters, $channel === 'inbox'
              ? (int) config('integrations.communications.detail_max_pages', 3)
              : 1),
            ['logs' => [], 'next_page_token' => null, 'warning' => null],
            $warnings,
          );
          if ($callPayload['warning'] ?? null) {
            $warnings[] = $callPayload['warning'];
          }
          $callLogs = $this->filterCallLogs($callPayload['logs'], $filters);
          $nextPageToken = $callPayload['next_page_token'];
          $callStats = $this->data->callStatsFromLogs($callLogs);
        } elseif ($callStats === []) {
          $callStats = $summaryStats;
        }

        if ($channel === 'inbox') {
          $contactPayload = $this->safeDataLoad(
            fn () => $this->contacts->buildIndexPayload($filters),
            ['contacts' => [], 'error' => null],
            $warnings,
          );
          $contacts = $contactPayload['contacts'];
          if ($contactPayload['error']) {
            $errors[] = $contactPayload['error'];
          }
        }

        if (in_array($channel, ['voicemail', 'inbox'], true)) {
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

        if (in_array($channel, ['sms', 'inbox'], true)) {
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
          $recPayload = $this->safeDataLoad(
            fn () => $this->data->recordings($filters),
            ['recordings' => [], 'next_page_token' => null, 'warnings' => []],
            $warnings,
          );
          $recordings = $recPayload['recordings'];
          $nextPageToken = $recPayload['next_page_token'];
          $warnings = array_merge($warnings, $recPayload['warnings'] ?? []);
        }

        if ($channel === 'team') {
          $teamUsers = collect($this->safeDataLoad(
            fn () => $this->data->users(),
            [],
            $warnings,
          ))
            ->map(fn (array $user) => [
              'id' => $user['id'] ?? null,
              'name' => trim(($user['first_name'] ?? '').' '.($user['last_name'] ?? '')) ?: ($user['email'] ?? 'Zoom user'),
              'email' => $user['email'] ?? null,
              'type' => $user['type'] ?? 'user',
              'status' => $user['status'] ?? 'active',
              'last_login_time' => $user['last_login_time'] ?? null,
            ])
            ->values()
            ->all();
          $queuePayload = $this->safeDataLoad(
            fn () => $this->data->callQueues($filters),
            ['queues' => [], 'warning' => null],
            $warnings,
          );
          $teamQueues = $queuePayload['queues'];
          $queueWarning = $queuePayload['warning'];
          if ($queuePayload['warning']) {
            $warnings[] = $queuePayload['warning'];
          }
        }

        $sidebarItems = $this->buildSidebarItems(
          $channel,
          $contacts,
          $callLogs,
          $voiceMails,
          $smsSessions,
          $chatChannels,
          $recordings,
          $teamUsers,
          $request,
          $routePrefix,
        );

        if ($panel === 'contact' && $request->filled('contact')) {
          $show = $this->contacts->buildShowPayload((string) $request->get('contact'), $filters);
          $selectedContact = $show['contact'];
          $timeline = $show['timeline'];
          $contactStats = $show['stats'];
          $smsSession = $show['sms_session'] ?? null;
          if ($show['error']) {
            $errors[] = $show['error'];
          }
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
          $summaryStats,
          $summaryVms,
          $summarySms,
          $chatChannels,
          $recordings,
        );
      } catch (\Throwable $e) {
        $errors[] = $this->zoom->humanizeError($e->getMessage());
      }
    }

    return [
      'channel' => $channel,
      'panel' => $panel,
      'filters' => $filters,
      'routePrefix' => $routePrefix,
      'channels' => self::CHANNELS,
      'sidebarItems' => $sidebarItems,
      'contacts' => $contacts,
      'callLogs' => $callLogs,
      'callStats' => $callStats,
      'voiceMails' => $voiceMails,
      'smsSessions' => $smsSessions,
      'chatChannels' => $chatChannels,
      'recordings' => $recordings,
      'teamUsers' => $teamUsers,
      'teamQueues' => $teamQueues,
      'queueWarning' => $queueWarning ?? null,
      'phoneUsers' => $phoneUsers,
      'recentNumbers' => $recentNumbers,
      'nextPageToken' => $nextPageToken,
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
      'connectionDiagnostics' => in_array($channel, ['calls', 'recordings', 'sms', 'voicemail'], true)
        ? $this->zoom->connectionDiagnostics()
        : null,
      'settings' => [
        'accountId' => $this->zoom->accountId(),
        'clientId' => $this->zoom->clientId(),
        'maskedSecret' => $this->zoom->maskedSecret(),
        'webhookSecret' => $this->zoom->webhookSecret(),
        'requiredScopes' => $this->zoom->requiredScopes(),
      ],
      'defaultCallerId' => config('integrations.communications.default_caller_id'),
      'prefillNumber' => $request->get('number'),
      'error' => $errors !== [] ? implode(' ', array_unique($errors)) : null,
      'warnings' => array_values(array_unique($warnings)),
      'clickToCall' => $this->clickToCall,
      'channelCounts' => $channelCounts,
    ];
  }

  public function resolveChannel(Request $request): string
  {
    if ($request->filled('channel')) {
      $channel = (string) $request->get('channel');

      return array_key_exists($channel, self::CHANNELS) ? $channel : 'inbox';
    }

    $mode = (string) $request->get('mode', 'inbox');

    return match ($mode) {
      'contacts', 'inbox', '' => 'inbox',
      'calls' => 'calls',
      'dialer' => 'inbox',
      'recordings' => 'recordings',
      'voicemails' => 'voicemail',
      'sms' => 'sms',
      'chat' => 'chat',
      'team' => 'team',
      'settings', 'zoom' => 'inbox',
      default => 'inbox',
    };
  }

  public function resolvePanel(Request $request, string $channel): string
  {
    if ($request->get('panel') === 'settings') {
      return 'settings';
    }

    if ($request->get('panel') === 'dialer' || $request->get('mode') === 'dialer') {
      return 'dialer';
    }

    if ($request->get('panel') === 'compose_sms') {
      return 'compose_sms';
    }

    if ($request->filled('contact')) {
      return 'contact';
    }

    if ($request->filled('session')) {
      return 'sms';
    }

    if ($request->filled('chat_owner') && ($request->filled('chat_channel') || $request->filled('chat_contact'))) {
      return 'chat';
    }

    if ($request->filled('call')) {
      return 'call';
    }

    if ($request->filled('voicemail')) {
      return 'voicemail';
    }

    if ($request->filled('recording')) {
      return 'recording';
    }

    if ($request->get('mode') === 'zoom' || $request->get('zoom_tab') === 'settings') {
      return 'settings';
    }

    return match ($channel) {
      'team' => 'team',
      'recordings' => 'recordings',
      'voicemail' => 'voicemails',
      'calls' => 'calls',
      default => 'empty',
    };
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
  ): array {
    return [
      'inbox' => count($contacts),
      'calls' => count($callLogs) ?: (int) ($summaryStats['total'] ?? 0),
      'calls_total' => (int) ($summaryStats['total'] ?? 0),
      'calls_missed' => (int) ($summaryStats['missed'] ?? 0),
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
}
