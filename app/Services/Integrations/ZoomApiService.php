<?php

namespace App\Services\Integrations;

use App\Services\Communications\CommunicationsDataService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ZoomApiService
{
    public function isConfigured(): bool
    {
        return filled(config('integrations.zoom.account_id'))
            && filled(config('integrations.zoom.client_id'))
            && filled(config('integrations.zoom.client_secret'));
    }

    /**
     * @return array{connected: bool, message: string, expires_at: string|null}
     */
    public function connectionStatus(): array
    {
        if (! $this->isConfigured()) {
            return [
                'connected' => false,
                'message' => 'Add Zoom credentials to your environment configuration.',
                'expires_at' => null,
            ];
        }

        try {
            $token = $this->accessToken();

            return [
                'connected' => true,
                'message' => 'Connected to Zoom API.',
                'expires_at' => $token['expires_at'] ?? null,
            ];
        } catch (\Throwable $e) {
            return [
                'connected' => false,
                'message' => $this->friendlyError($e->getMessage()),
                'expires_at' => null,
            ];
        }
    }

    /**
     * @return array{phone_available: bool, messages: array<int, string>}
     */
    public function connectionDiagnostics(): array
    {
        if (! $this->isConfigured()) {
            return ['phone_available' => false, 'messages' => ['Zoom is not configured.']];
        }

        return Cache::remember('zoom.connection.diagnostics', now()->addMinutes(10), function () {
            return $this->probeConnectionHints();
        });
    }

    /**
     * @return array{phone_available: bool, messages: array<int, string>}
     */
    protected function probeConnectionHints(): array
    {
        $messages = [];
        $phoneAvailable = true;

        try {
            $this->request('get', '/phone/call_history', [
                'from' => now()->subDays(7)->toDateString(),
                'to' => now()->toDateString(),
                'page_size' => 1,
            ]);
        } catch (\RuntimeException $e) {
            if ($this->isPhoneUnavailableError($e->getMessage())) {
                $phoneAvailable = false;
                $messages[] = 'Zoom Phone is not enabled on this account. Enable it in Zoom Admin to use call logs, SMS, and phone recordings.';
            } elseif ($scopes = $this->extractMissingScopes($this->parseZoomMessage($e->getMessage()))) {
                $messages[] = "Missing Zoom scope(s): {$scopes}. Add in Marketplace, save, then Refresh data.";
            }
        }

        try {
            $this->request('get', '/users/'.($this->listUsers(['per_page' => 1])['users'][0]['id'] ?? '').'/recordings', [
                'from' => now()->subDays(7)->toDateString(),
                'to' => now()->toDateString(),
                'page_size' => 1,
            ]);
        } catch (\RuntimeException $e) {
            if ($scopes = $this->extractMissingScopes($this->parseZoomMessage($e->getMessage()))) {
                $messages[] = "Cloud recordings need scope(s): {$scopes}. Add in Marketplace, save, then Refresh data.";
            }
        }

        return [
            'phone_available' => $phoneAvailable,
            'messages' => array_values(array_unique($messages)),
        ];
    }

    /**
     * @return array{users: array<int, array<string, mixed>>, next_page_token: string|null}
     */
    public function listUsers(array $filters = []): array
    {
        $response = $this->request('get', '/users', [
            'page_size' => min((int) ($filters['per_page'] ?? 50), 100),
            'next_page_token' => $filters['page_token'] ?? null,
            'status' => 'active',
        ]);

        return [
            'users' => $response['users'] ?? [],
            'next_page_token' => $response['next_page_token'] ?? null,
        ];
    }

    /**
     * @return array{users: array<int, array<string, mixed>>, next_page_token: string|null, warning: string|null}
     */
    public function listPhoneUsers(array $filters = []): array
    {
        try {
            $response = $this->request('get', '/phone/users', [
                'page_size' => min((int) ($filters['per_page'] ?? 50), 50),
                'next_page_token' => $filters['page_token'] ?? null,
            ]);
        } catch (\RuntimeException $e) {
            if ($this->isPhoneUserScopeError($e->getMessage()) || $this->isPhoneUnavailableError($e->getMessage())) {
                return [
                    'users' => [],
                    'next_page_token' => null,
                    'warning' => $this->humanizeError($e->getMessage()),
                ];
            }

            throw $e;
        }

        $users = [];
        foreach ($response['users'] ?? [] as $row) {
            $users[] = $this->mapPhoneUserRow($row);
        }

        return [
            'users' => $users,
            'next_page_token' => $response['next_page_token'] ?? null,
            'warning' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function mapPhoneUserRow(array $row): array
    {
        $phoneNumbers = collect($row['phone_numbers'] ?? [])
            ->map(fn ($entry) => is_array($entry) ? ($entry['number'] ?? null) : $entry)
            ->filter()
            ->values()
            ->all();

        $name = $row['name']
            ?? trim(($row['first_name'] ?? '').' '.($row['last_name'] ?? ''))
            ?: ($row['email'] ?? 'Phone user');

        $extension = $row['extension_number'] ?? null;
        $defaultCallerId = $phoneNumbers[0] ?? (filled($extension) ? (string) $extension : null);

        return [
            'id' => $row['id'] ?? null,
            'name' => $name,
            'email' => $row['email'] ?? null,
            'extension_number' => $extension,
            'phone_numbers' => $phoneNumbers,
            'default_caller_id' => $defaultCallerId,
            'status' => $row['status'] ?? 'active',
        ];
    }

    protected function isPhoneUserScopeError(string $message): bool
    {
        $zoomMessage = strtolower($this->parseZoomMessage($message));

        return str_contains($zoomMessage, 'phone:')
            && str_contains($zoomMessage, 'user')
            && (str_contains($zoomMessage, 'scope') || str_contains($zoomMessage, 'scopes'));
    }

    /**
     * @return array{call_logs: array<int, array<string, mixed>>, next_page_token: string|null, warning: string|null}
     */
    public function listCallLogs(array $filters = []): array
    {
        $from = $filters['from'] ?? now()->subDays(30)->toDateString();
        $to = $filters['to'] ?? now()->toDateString();
        $query = [
            'from' => $from,
            'to' => $to,
            'page_size' => min((int) ($filters['per_page'] ?? 50), 50),
            'next_page_token' => $filters['page_token'] ?? null,
        ];
        $warnings = [];

        try {
            $response = $this->request('get', '/phone/call_history', $query);

            return [
                'call_logs' => $response['call_history'] ?? [],
                'next_page_token' => $response['next_page_token'] ?? null,
                'warning' => null,
            ];
        } catch (\RuntimeException $e) {
            if ($this->isPhoneUnavailableError($e->getMessage())) {
                $warnings[] = $this->humanizeError($e->getMessage());
            } elseif ($this->isCallLogScopeError($e->getMessage())) {
                $warnings[] = $this->humanizeError($e->getMessage());
            } else {
                throw $e;
            }
        }

        try {
            $response = $this->request('get', '/phone/call_logs', $query);

            return [
                'call_logs' => $response['call_logs'] ?? [],
                'next_page_token' => $response['next_page_token'] ?? null,
                'warning' => $warnings !== [] ? implode(' ', array_unique($warnings)) : null,
            ];
        } catch (\RuntimeException $e) {
            if ($this->isPhoneUnavailableError($e->getMessage())) {
                $warnings[] = $this->humanizeError($e->getMessage());
            } elseif ($this->isCallLogScopeError($e->getMessage())) {
                $warnings[] = $this->humanizeError($e->getMessage());
            } else {
                throw $e;
            }
        }

        if ($this->shouldUsePerUserFallback($warnings)) {
            $userPayload = $this->listCallLogsFromUsers(array_merge($filters, ['from' => $from, 'to' => $to]));
            if ($userPayload['call_logs'] !== []) {
                return $userPayload;
            }

            if (filled($userPayload['warning'] ?? null)) {
                $warnings[] = $userPayload['warning'];
            }
        }

        return [
            'call_logs' => [],
            'next_page_token' => null,
            'warning' => $warnings !== [] ? implode(' ', array_unique($warnings)) : null,
        ];
    }

    /**
     * @return array{call_logs: array<int, array<string, mixed>>, next_page_token: string|null, warning: string|null}
     */
    protected function listCallLogsFromUsers(array $filters): array
    {
        $from = $filters['from'] ?? now()->subDays(14)->toDateString();
        $to = $filters['to'] ?? now()->toDateString();
        $merged = [];
        $warning = null;

        try {
            $usersPayload = $this->listUsers([
                'per_page' => (int) config('integrations.communications.user_fallback_max_users', 25),
            ]);
        } catch (\RuntimeException $e) {
            return [
                'call_logs' => [],
                'next_page_token' => null,
                'warning' => $this->humanizeError($e->getMessage()),
            ];
        }

        $query = array_filter([
            'from' => $from,
            'to' => $to,
            'page_size' => 50,
        ]);

        $token = $this->accessToken()['access_token'];
        $base = rtrim(config('integrations.zoom.api_base'), '/');

        $responses = Http::pool(function (\Illuminate\Http\Client\Pool $pool) use ($usersPayload, $token, $base, $query) {
            $requests = [];

            foreach ($usersPayload['users'] as $user) {
                $userId = $user['id'] ?? null;
                if (! $userId) {
                    continue;
                }

                $requests[] = $pool->as((string) $userId)
                    ->withToken($token)
                    ->acceptJson()
                    ->get($base.'/phone/users/'.$userId.'/call_history', $query);
            }

            return $requests;
        });

        foreach ($responses as $response) {
            if ($response instanceof \Illuminate\Http\Client\Response && $response->successful()) {
                foreach ($response->json('call_history') ?? [] as $row) {
                    $key = (string) ($row['call_history_uuid'] ?? $row['call_id'] ?? Str::uuid()->toString());
                    $merged[$key] = $row;
                }

                continue;
            }

            if ($response instanceof \Illuminate\Http\Client\Response && ! $warning) {
                $warning = $this->humanizeError('Zoom API error: '.$response->body());
            }
        }

        return [
            'call_logs' => array_values($merged),
            'next_page_token' => null,
            'warning' => $warning,
        ];
    }

    /**
     * @return array{recordings: array<int, array<string, mixed>>, next_page_token: string|null}
     */
    public function listRecordings(array $filters = []): array
    {
        $warnings = [];
        $phonePayload = $this->listPhoneRecordings($filters);

        if (filled($phonePayload['warning'] ?? null)) {
            $warnings[] = $phonePayload['warning'];
        }

        if ($phonePayload['recordings'] !== []) {
            return [
                'recordings' => $phonePayload['recordings'],
                'next_page_token' => $phonePayload['next_page_token'],
                'warnings' => $warnings,
            ];
        }

        $recordings = [];
        $nextPageToken = null;

        try {
            $cloudPayload = $this->listAccountRecordings($filters);
            $recordings = $cloudPayload['recordings'];
            $nextPageToken = $cloudPayload['next_page_token'];
        } catch (\RuntimeException $e) {
            if (! $this->isRecordingScopeError($e->getMessage())) {
                $warnings[] = $this->humanizeError($e->getMessage());
            }
        }

        if ($recordings === [] && $this->shouldUsePerUserFallback($warnings)) {
            $userPayload = $this->listRecordingsFromUsers($filters);
            $recordings = $userPayload['recordings'];
            if (filled($userPayload['warning'] ?? null)) {
                $warnings[] = $userPayload['warning'];
            }
        }

        if ($recordings !== []) {
            return [
                'recordings' => $recordings,
                'next_page_token' => $nextPageToken,
                'warnings' => $warnings,
            ];
        }

        $fromHistory = $this->recordingsFromCallHistory($filters);
        if ($fromHistory !== []) {
            return [
                'recordings' => $fromHistory,
                'next_page_token' => null,
                'warnings' => $warnings,
            ];
        }

        return [
            'recordings' => [],
            'next_page_token' => null,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array{recordings: array<int, array<string, mixed>>, next_page_token: string|null, warning: string|null}
     */
    public function listPhoneRecordings(array $filters = []): array
    {
        $from = $filters['from'] ?? now()->subDays(30)->toDateString();
        $to = $filters['to'] ?? now()->toDateString();

        try {
            $response = $this->request('get', '/phone/recordings', [
                'from' => $from,
                'to' => $to,
                'page_size' => min((int) ($filters['per_page'] ?? 50), 50),
                'next_page_token' => $filters['page_token'] ?? null,
            ]);
        } catch (\RuntimeException $e) {
            if ($this->isPhoneRecordingScopeError($e->getMessage()) || $this->isPhoneUnavailableError($e->getMessage())) {
                return [
                    'recordings' => [],
                    'next_page_token' => null,
                    'warning' => $this->humanizeError($e->getMessage()),
                ];
            }

            throw $e;
        }

        $recordings = [];
        foreach ($response['recordings'] ?? [] as $row) {
            $recordings[] = $this->mapPhoneRecordingRow($row);
        }

        return [
            'recordings' => $recordings,
            'next_page_token' => $response['next_page_token'] ?? null,
            'warning' => null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function recordingsFromCallHistory(array $filters): array
    {
        $from = $filters['from'] ?? now()->subDays(30)->toDateString();
        $to = $filters['to'] ?? now()->toDateString();
        $rows = [];
        $pageToken = null;
        $pages = 0;

        do {
            $payload = $this->listCallLogs([
                'from' => $from,
                'to' => $to,
                'page_token' => $pageToken,
                'per_page' => 50,
            ]);

            foreach ($payload['call_logs'] as $row) {
                $status = strtolower((string) ($row['recording_status'] ?? ''));
                if ($status !== '' && $status !== 'non_recorded') {
                    $rows[] = $row;
                }
            }

            $pageToken = $payload['next_page_token'] ?? null;
            $pages++;
        } while ($pageToken && $pages < 10 && count($rows) < 500);

        return collect($rows)
            ->map(function (array $row) {
                $uuid = (string) ($row['call_history_uuid'] ?? $row['id'] ?? Str::uuid()->toString());
                $caller = $row['caller_name'] ?? $row['caller_did_number'] ?? 'Caller';
                $callee = $row['callee_name'] ?? $row['callee_did_number'] ?? 'Callee';

                return [
                    'id' => $uuid,
                    'source' => 'phone',
                    'topic' => "Phone call · {$caller} → {$callee}",
                    'host' => $caller,
                    'start_time' => $row['start_time'] ?? null,
                    'duration' => (int) ($row['duration'] ?? 0),
                    'file_type' => ucfirst((string) ($row['recording_status'] ?? 'phone')),
                    'has_media' => true,
                    'call_history_uuid' => $uuid,
                ];
            })
            ->values()
            ->all();
    }

    protected function recordingMergeKey(array $recording): string
    {
        return (string) ($recording['call_history_uuid'] ?? $recording['id']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function normalizedCallLogs(array $filters = []): array
    {
        return app(CommunicationsDataService::class)->callLogs(
            $filters,
            (int) config('integrations.communications.list_max_pages', 1)
        )['logs'];
    }

    /**
     * @return array{voice_mails: array<int, array<string, mixed>>, next_page_token: string|null, warning: string|null}
     */
    public function listVoiceMails(array $filters = []): array
    {
        [$from, $to] = $this->clampDateRange(
            $filters['from'] ?? now()->subDays((int) config('integrations.communications.default_days', 14))->toDateString(),
            $filters['to'] ?? now()->toDateString(),
            30
        );

        $warnings = [];
        $voiceMails = [];

        try {
            $query = [
                'from' => $from,
                'to' => $to,
                'page_size' => min((int) ($filters['per_page'] ?? 50), 50),
                'next_page_token' => $filters['page_token'] ?? null,
            ];

            if (($filters['status'] ?? 'all') !== 'all') {
                $query['status'] = $filters['status'];
            }

            $response = $this->request('get', '/phone/voice_mails', $query);

            foreach ($response['voice_mails'] ?? [] as $row) {
                $voiceMails[$row['id'] ?? Str::uuid()->toString()] = $this->mapVoiceMailRow($row);
            }
        } catch (\RuntimeException $e) {
            $warnings[] = $this->humanizeError($e->getMessage());
        }

        if ($voiceMails === [] && $this->shouldUsePerUserFallback($warnings)) {
            $userPayload = $this->listVoiceMailsFromUsers(array_merge($filters, ['from' => $from, 'to' => $to]));
            foreach ($userPayload['voice_mails'] as $row) {
                $voiceMails[$row['id']] = $row;
            }

            if (filled($userPayload['warning'] ?? null)) {
                $warnings[] = $userPayload['warning'];
            }
        }

        $sorted = collect($voiceMails)
            ->sortByDesc(fn (array $row) => $row['date_time'] ?? '')
            ->values()
            ->all();

        return [
            'voice_mails' => $sorted,
            'next_page_token' => null,
            'warning' => $warnings !== [] ? implode(' ', array_unique($warnings)) : null,
        ];
    }

    /**
     * @return array{voice_mails: array<int, array<string, mixed>>, warning: string|null}
     */
    protected function listVoiceMailsFromUsers(array $filters): array
    {
        $from = $filters['from'] ?? now()->subDays(14)->toDateString();
        $to = $filters['to'] ?? now()->toDateString();
        $merged = [];
        $warning = null;

        try {
            $usersPayload = $this->listUsers([
                'per_page' => (int) config('integrations.communications.user_fallback_max_users', 25),
            ]);
        } catch (\RuntimeException $e) {
            return [
                'voice_mails' => [],
                'warning' => $this->humanizeError($e->getMessage()),
            ];
        }

        $query = array_filter([
            'from' => $from,
            'to' => $to,
            'page_size' => 50,
            'status' => ($filters['status'] ?? 'all') !== 'all' ? $filters['status'] : null,
        ]);

        $token = $this->accessToken()['access_token'];
        $base = rtrim(config('integrations.zoom.api_base'), '/');

        $responses = Http::pool(function (\Illuminate\Http\Client\Pool $pool) use ($usersPayload, $token, $base, $query) {
            $requests = [];

            foreach ($usersPayload['users'] as $user) {
                $userId = $user['id'] ?? null;
                if (! $userId) {
                    continue;
                }

                $requests[] = $pool->as((string) $userId)
                    ->withToken($token)
                    ->acceptJson()
                    ->get($base.'/phone/users/'.$userId.'/voice_mails', $query);
            }

            return $requests;
        });

        foreach ($responses as $response) {
            if ($response instanceof \Illuminate\Http\Client\Response && $response->successful()) {
                foreach ($response->json('voice_mails') ?? [] as $row) {
                    $merged[$row['id'] ?? Str::uuid()->toString()] = $this->mapVoiceMailRow($row);
                }

                continue;
            }

            if ($response instanceof \Illuminate\Http\Client\Response && ! $warning) {
                $warning = $this->humanizeError('Zoom API error: '.$response->body());
            }
        }

        return [
            'voice_mails' => array_values($merged),
            'warning' => $warning,
        ];
    }

    /**
     * @return array{sessions: array<int, array<string, mixed>>, next_page_token: string|null, warning: string|null}
     */
    public function listSmsSessions(array $filters = []): array
    {
        $warnings = [];
        $sessions = [];

        try {
            $response = $this->request('get', '/phone/sms/sessions', [
                'page_size' => min((int) ($filters['per_page'] ?? 50), 50),
                'next_page_token' => $filters['page_token'] ?? null,
            ]);

            foreach ($response['sms_sessions'] ?? [] as $row) {
                if (filled($row['session_id'] ?? null)) {
                    $sessions[$row['session_id']] = $this->mapSmsSessionRow($row);
                }
            }
        } catch (\RuntimeException $e) {
            $warnings[] = $this->humanizeError($e->getMessage());
        }

        if ($sessions === [] && $this->shouldUsePerUserFallback($warnings)) {
            $userPayload = $this->listSmsSessionsFromUsers($filters);
            foreach ($userPayload['sessions'] as $row) {
                if (filled($row['session_id'] ?? null)) {
                    $sessions[$row['session_id']] = $row;
                }
            }

            if (filled($userPayload['warning'] ?? null)) {
                $warnings[] = $userPayload['warning'];
            }
        }

        $sorted = collect($sessions)
            ->sortByDesc(fn (array $row) => $row['last_access_time'] ?? '')
            ->values()
            ->all();

        return [
            'sessions' => $sorted,
            'next_page_token' => null,
            'warning' => $warnings !== [] ? implode(' ', array_unique($warnings)) : null,
        ];
    }

    /**
     * @return array{sessions: array<int, array<string, mixed>>, warning: string|null}
     */
    protected function listSmsSessionsFromUsers(array $filters): array
    {
        $merged = [];
        $warning = null;

        try {
            $usersPayload = $this->listUsers([
                'per_page' => (int) config('integrations.communications.user_fallback_max_users', 25),
            ]);
        } catch (\RuntimeException $e) {
            return [
                'sessions' => [],
                'warning' => $this->humanizeError($e->getMessage()),
            ];
        }

        $query = array_filter([
            'page_size' => min((int) ($filters['per_page'] ?? 50), 50),
            'next_page_token' => $filters['page_token'] ?? null,
        ]);

        $token = $this->accessToken()['access_token'];
        $base = rtrim(config('integrations.zoom.api_base'), '/');

        foreach (['sms/sessions', 'sms/sessions/sync'] as $segment) {
            $responses = Http::pool(function (\Illuminate\Http\Client\Pool $pool) use ($usersPayload, $token, $base, $query, $segment) {
                $requests = [];

                foreach ($usersPayload['users'] as $user) {
                    $userId = $user['id'] ?? null;
                    if (! $userId) {
                        continue;
                    }

                    $requests[] = $pool->as((string) $userId)
                        ->withToken($token)
                        ->acceptJson()
                        ->get($base.'/phone/users/'.$userId.'/'.$segment, $query);
                }

                return $requests;
            });

            foreach ($responses as $response) {
                if ($response instanceof \Illuminate\Http\Client\Response && $response->successful()) {
                    foreach ($response->json('sms_sessions') ?? [] as $row) {
                        if (filled($row['session_id'] ?? null)) {
                            $merged[$row['session_id']] = $this->mapSmsSessionRow($row);
                        }
                    }

                    continue;
                }

                if ($response instanceof \Illuminate\Http\Client\Response && ! $warning) {
                    $warning = $this->humanizeError('Zoom API error: '.$response->body());
                }
            }

            if ($merged !== []) {
                break;
            }
        }

        return [
            'sessions' => array_values($merged),
            'warning' => $warning,
        ];
    }

    /**
     * @return array{messages: array<int, array<string, mixed>>, next_page_token: string|null}
     */
    public function getSmsSessionMessages(string $sessionId, array $filters = []): array
    {
        foreach (['/phone/sms/sessions/'.$sessionId, '/phone/sms/sessions/'.$sessionId.'/sync'] as $path) {
            try {
                $payload = $this->fetchSmsMessages($path, $filters);
                if ($payload['messages'] !== []) {
                    return $payload;
                }
            } catch (\RuntimeException) {
                continue;
            }
        }

        return $this->fetchSmsMessagesFromUsers($sessionId, $filters);
    }

    /**
     * @return array{messages: array<int, array<string, mixed>>, next_page_token: string|null}
     */
    protected function fetchSmsMessagesFromUsers(string $sessionId, array $filters): array
    {
        try {
            $usersPayload = $this->listUsers(['per_page' => 50]);
        } catch (\RuntimeException) {
            return ['messages' => [], 'next_page_token' => null];
        }

        foreach ($usersPayload['users'] as $user) {
            $userId = $user['id'] ?? null;
            if (! $userId) {
                continue;
            }

            foreach (
                [
                    '/phone/users/'.$userId.'/sms/sessions/'.$sessionId,
                    '/phone/users/'.$userId.'/sms/sessions/'.$sessionId.'/sync',
                ] as $path
            ) {
                try {
                    $payload = $this->fetchSmsMessages($path, $filters);
                    if ($payload['messages'] !== []) {
                        return $payload;
                    }
                } catch (\RuntimeException) {
                    continue;
                }
            }
        }

        return ['messages' => [], 'next_page_token' => null];
    }

    /**
     * @return array{messages: array<int, array<string, mixed>>, next_page_token: string|null}
     */
    protected function fetchSmsMessages(string $path, array $filters): array
    {
        $response = $this->request('get', $path, [
            'page_size' => min((int) ($filters['per_page'] ?? 50), 50),
            'next_page_token' => $filters['page_token'] ?? null,
        ]);

        $messages = [];
        foreach ($response['sms_histories'] ?? $response['messages'] ?? [] as $row) {
            $messages[] = $this->mapSmsMessageRow($row);
        }

        return [
            'messages' => $messages,
            'next_page_token' => $response['next_page_token'] ?? null,
        ];
    }

    /**
     * @return array{queues: array<int, array<string, mixed>>, next_page_token: string|null, warning: string|null}
     */
    public function listCallQueues(array $filters = []): array
    {
        try {
            $response = $this->request('get', '/phone/call_queues', [
                'page_size' => min((int) ($filters['per_page'] ?? 50), 50),
                'next_page_token' => $filters['page_token'] ?? null,
            ]);
        } catch (\RuntimeException $e) {
            if ($this->isCallQueueScopeError($e->getMessage()) || $this->isPhoneUnavailableError($e->getMessage())) {
                return [
                    'queues' => [],
                    'next_page_token' => null,
                    'warning' => $this->humanizeError($e->getMessage()),
                ];
            }

            throw $e;
        }

        $queues = [];
        foreach ($response['call_queues'] ?? [] as $row) {
            $queues[] = [
                'id' => $row['id'] ?? null,
                'name' => $row['name'] ?? 'Call queue',
                'extension_number' => $row['extension_number'] ?? null,
                'status' => $row['status'] ?? 'unknown',
                'site' => $row['site']['name'] ?? null,
                'phone_numbers' => collect($row['phone_numbers'] ?? [])
                    ->pluck('number')
                    ->filter()
                    ->values()
                    ->all(),
            ];
        }

        return [
            'queues' => $queues,
            'next_page_token' => $response['next_page_token'] ?? null,
            'warning' => null,
        ];
    }

    /**
     * @return array{channels: array<int, array<string, mixed>>, next_page_token: string|null, warning: string|null}
     */
    public function listTeamChatChannels(array $filters = []): array
    {
        $channels = [];
        $warnings = [];

        try {
            $usersPayload = $this->listUsers([
                'per_page' => (int) config('integrations.communications.user_fallback_max_users', 25),
            ]);
        } catch (\RuntimeException $e) {
            return [
                'channels' => [],
                'next_page_token' => null,
                'warning' => $this->humanizeError($e->getMessage()),
            ];
        }

        $token = $this->accessToken()['access_token'];
        $base = rtrim(config('integrations.zoom.api_base'), '/');
        $query = array_filter([
            'page_size' => min((int) ($filters['per_page'] ?? 50), 50),
            'next_page_token' => $filters['page_token'] ?? null,
        ]);

        $responses = Http::pool(function (\Illuminate\Http\Client\Pool $pool) use ($usersPayload, $token, $base, $query) {
            $requests = [];

            foreach ($usersPayload['users'] as $user) {
                $userId = $user['id'] ?? null;
                if (! $userId) {
                    continue;
                }

                $requests[] = $pool->as((string) $userId)
                    ->withToken($token)
                    ->acceptJson()
                    ->get($base.'/chat/users/'.$userId.'/channels', $query);
            }

            return $requests;
        });

        foreach ($usersPayload['users'] as $user) {
            $userId = (string) ($user['id'] ?? '');
            if ($userId === '') {
                continue;
            }

            $response = $responses[$userId] ?? null;
            if (! $response instanceof \Illuminate\Http\Client\Response) {
                continue;
            }

            if (! $response->successful()) {
                if ($this->isTeamChatScopeError($response->body())) {
                    $warnings[] = $this->humanizeError('Zoom API error: '.$response->body());
                }

                continue;
            }

            foreach ($response->json('channels') ?? [] as $row) {
                if (! filled($row['id'] ?? null)) {
                    continue;
                }

                $mapped = $this->mapTeamChatChannelRow($row, $user);
                $channels[$mapped['thread_key']] = $mapped;
            }
        }

        $sorted = collect($channels)
            ->sortByDesc(fn (array $row) => $row['last_message_sent_time'] ?? '')
            ->values()
            ->all();

        return [
            'channels' => $sorted,
            'next_page_token' => null,
            'warning' => $warnings !== [] ? implode(' ', array_unique($warnings)) : null,
        ];
    }

    /**
     * @return array{messages: array<int, array<string, mixed>>, next_page_token: string|null}
     */
    public function getTeamChatMessages(string $ownerUserId, array $filters = []): array
    {
        $query = [
            'page_size' => min((int) ($filters['per_page'] ?? 50), 50),
            'next_page_token' => $filters['page_token'] ?? null,
        ];

        if (filled($filters['to_channel'] ?? null)) {
            $query['to_channel'] = $filters['to_channel'];
        } elseif (filled($filters['to_contact'] ?? null)) {
            $query['to_contact'] = $filters['to_contact'];
        } else {
            return ['messages' => [], 'next_page_token' => null];
        }

        $response = $this->request('get', '/chat/users/'.$ownerUserId.'/messages', $query);

        $messages = [];
        foreach ($response['messages'] ?? [] as $row) {
            $messages[] = $this->mapTeamChatMessageRow($row, $ownerUserId);
        }

        return [
            'messages' => $messages,
            'next_page_token' => $response['next_page_token'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $user
     * @return array<string, mixed>
     */
    protected function mapTeamChatChannelRow(array $row, array $user): array
    {
        $userId = (string) ($user['id'] ?? '');
        $channelId = (string) ($row['id'] ?? '');
        $ownerName = trim(($user['first_name'] ?? '').' '.($user['last_name'] ?? '')) ?: ($user['email'] ?? 'Zoom user');
        $channelName = $row['name'] ?? 'Channel';

        return [
            'thread_key' => $userId.':'.$channelId,
            'channel_id' => $channelId,
            'owner_user_id' => $userId,
            'owner_name' => $ownerName,
            'owner_email' => $user['email'] ?? null,
            'name' => $channelName,
            'label' => $channelName,
            'type' => $this->teamChatChannelTypeLabel($row['type'] ?? null),
            'member_count' => (int) ($row['members_count'] ?? $row['member_count'] ?? 0),
            'last_message_sent_time' => $row['last_message_sent_time'] ?? null,
            'thread_type' => 'channel',
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function mapTeamChatMessageRow(array $row, string $ownerUserId): array
    {
        $sender = is_array($row['sender'] ?? null)
            ? ($row['sender']['display_name'] ?? $row['sender']['name'] ?? $row['sender']['email'] ?? 'Unknown')
            : ($row['sender_display_name'] ?? $row['sender'] ?? 'Unknown');

        $senderId = is_array($row['sender'] ?? null)
            ? ($row['sender']['id'] ?? null)
            : ($row['sender_id'] ?? null);

        $isOutbound = filled($senderId) && $senderId === $ownerUserId;

        return [
            'id' => (string) ($row['id'] ?? $row['message_id'] ?? Str::uuid()->toString()),
            'message' => $row['message'] ?? $row['text'] ?? '',
            'date_time' => $row['date_time'] ?? $row['timestamp'] ?? null,
            'sender' => (string) $sender,
            'sender_id' => $senderId,
            'direction' => $isOutbound ? 'outbound' : 'inbound',
            'thread_type' => filled($row['to_channel'] ?? null) ? 'channel' : 'contact',
        ];
    }

    protected function teamChatChannelTypeLabel(mixed $type): string
    {
        return match ((int) $type) {
            1 => 'private',
            2 => 'public',
            3 => 'instant',
            4 => 'shared',
            default => is_string($type) && $type !== '' ? $type : 'channel',
        };
    }

    protected function isTeamChatScopeError(string $message): bool
    {
        $zoomMessage = strtolower($this->parseZoomMessage($message));

        return (str_contains($zoomMessage, 'team_chat') || str_contains($zoomMessage, 'chat_channel'))
            && (str_contains($zoomMessage, 'scope') || str_contains($zoomMessage, 'scopes'));
    }

    public function streamVoicemail(string $fileId, bool $download): \Symfony\Component\HttpFoundation\Response
    {
        $meta = $this->resolveVoicemailMeta($fileId);
        $url = $meta['download_url'] ?? null;

        if (! filled($url)) {
            throw new \RuntimeException('Voicemail media URL is not available.');
        }

        return $this->streamZoomMedia(
            $url,
            'zoom-voicemail-'.$fileId.'.mp3',
            $meta['content_type'] ?? 'audio/mpeg',
            $download
        );
    }

    public function streamRecording(string $source, string $recordingId, bool $download, ?string $callReferenceId = null): \Symfony\Component\HttpFoundation\Response
    {
        $lastError = 'Recording not found or expired. Refresh the recordings list and try again.';

        foreach ($this->recordingLookupIds($recordingId, $callReferenceId) as $lookupId) {
            try {
                $url = $this->resolveRecordingMediaUrl($source, $lookupId, $download);

                return $this->streamZoomMedia(
                    $url,
                    'zoom-recording-'.$lookupId.'.mp3',
                    'audio/mpeg',
                    $download
                );
            } catch (\RuntimeException $e) {
                $lastError = $e->getMessage();
            }
        }

        throw new \RuntimeException($lastError);
    }

    /**
     * @return array<int, string>
     */
    public function recordingLookupIds(string $recordingId, ?string $callReferenceId = null): array
    {
        $ids = array_values(array_unique(array_filter([
            $callReferenceId,
            $recordingId,
        ])));

        return $ids !== [] ? $ids : [$recordingId];
    }

    public function compactZoomReferenceId(string $id): string
    {
        return strtolower(preg_replace('/[^a-z0-9]/', '', $id) ?? $id);
    }

    protected function resolveRecordingMediaUrl(string $source, string $recordingId, bool $download): string
    {
        $lastError = 'Recording not found or expired. Refresh the recordings list and try again.';

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $meta = $this->resolveRecordingMeta($source, $recordingId);
            } catch (\RuntimeException $e) {
                $lastError = $e->getMessage();
                break;
            }

            $url = $download
                ? ($meta['download_url'] ?? null)
                : ($meta['play_url'] ?? $meta['download_url'] ?? null);

            if (! filled($url)) {
                break;
            }

            try {
                $this->preflightZoomMediaUrl((string) $url);

                return $this->normalizeZoomMediaUrl((string) $url);
            } catch (\RuntimeException $e) {
                $lastError = $e->getMessage();
                Cache::forget($this->recordingCacheKey($recordingId));
            }
        }

        throw new \RuntimeException($lastError);
    }

    protected function preflightZoomMediaUrl(string $url): void
    {
        $url = $this->normalizeZoomMediaUrl($url);
        $token = $this->accessToken()['access_token'];

        $response = Http::withToken($token)
            ->withHeaders(['Range' => 'bytes=0-0'])
            ->withOptions(['allow_redirects' => ['max' => 5]])
            ->get($url);

        if ($response->successful() || $response->status() === 206) {
            return;
        }

        throw new \RuntimeException($this->humanizeZoomMediaFailure($response));
    }

    protected function humanizeZoomMediaFailure(\Illuminate\Http\Client\Response $response): string
    {
        $body = $response->body();

        if (filled($body) && (str_contains($body, 'scope') || str_contains($body, 'Invalid access token'))) {
            return $this->humanizeError('Zoom API error: '.$body);
        }

        return 'Recording file is not available from Zoom (HTTP '.$response->status().'). '
            .'Confirm phone:read:call_recording:admin is enabled, then run php artisan zoom:clear-token --cache.';
    }

    protected function streamZoomMedia(string $url, string $filename, string $contentType, bool $download): \Symfony\Component\HttpFoundation\Response
    {
        $candidates = array_values(array_unique(array_filter([
            $this->normalizeZoomMediaUrl($url),
            $this->alternatePhoneRecordingDownloadUrl($url),
        ])));

        $lastError = 'Recording file is not available from Zoom.';

        foreach ($candidates as $candidateUrl) {
            try {
                return $this->streamZoomMediaFromUrl($candidateUrl, $filename, $contentType, $download);
            } catch (\RuntimeException $e) {
                $lastError = $e->getMessage();
            }
        }

        throw new \RuntimeException($lastError);
    }

    protected function streamZoomMediaFromUrl(string $url, string $filename, string $contentType, bool $download): \Symfony\Component\HttpFoundation\Response
    {
        $token = $this->accessToken()['access_token'];

        $response = Http::withToken($token)
            ->withOptions(['allow_redirects' => ['max' => 5]])
            ->accept('*/*')
            ->get($url);

        if (! $response->successful()) {
            throw new \RuntimeException($this->humanizeZoomMediaFailure($response));
        }

        $body = $response->body();

        return response($body, 200, [
            'Content-Type' => $response->header('Content-Type') ?: $contentType,
            'Content-Length' => (string) strlen($body),
            'Content-Disposition' => ($download ? 'attachment' : 'inline').'; filename="'.$filename.'"',
            'Cache-Control' => 'private, max-age=3600',
            'Accept-Ranges' => 'bytes',
        ]);
    }

    protected function alternatePhoneRecordingDownloadUrl(string $url): ?string
    {
        $fileId = $this->extractPhoneRecordingFileId($url);
        if (! filled($fileId)) {
            return null;
        }

        $compact = $this->compactZoomReferenceId($fileId);
        if ($compact === $fileId) {
            return null;
        }

        return $this->phoneRecordingDownloadUrl($compact);
    }

    /**
     * @return array{recordings: array<int, array<string, mixed>>, next_page_token: string|null}
     */
    protected function listAccountRecordings(array $filters = []): array
    {
        $accountId = config('integrations.zoom.account_id');
        $from = $filters['from'] ?? now()->subDays(30)->toDateString();
        $to = $filters['to'] ?? now()->toDateString();

        $response = $this->request('get', "/accounts/{$accountId}/recordings", [
            'from' => $from,
            'to' => $to,
            'page_size' => min((int) ($filters['per_page'] ?? 15), 50),
            'next_page_token' => $filters['page_token'] ?? null,
        ]);

        return [
            'recordings' => $this->mapMeetingsToRecordings($response['meetings'] ?? []),
            'next_page_token' => $response['next_page_token'] ?? null,
        ];
    }

    /**
     * @return array{recordings: array<int, array<string, mixed>>, next_page_token: string|null}
     */
    protected function listRecordingsFromUsers(array $filters = []): array
    {
        $from = $filters['from'] ?? now()->subDays(30)->toDateString();
        $to = $filters['to'] ?? now()->toDateString();
        $recordings = [];
        $warning = null;

        try {
            $usersPayload = $this->listUsers([
                'per_page' => (int) config('integrations.communications.user_fallback_max_users', 25),
            ]);
        } catch (\RuntimeException $e) {
            return [
                'recordings' => [],
                'next_page_token' => null,
                'warning' => $this->humanizeError($e->getMessage()),
            ];
        }

        $query = array_filter([
            'from' => $from,
            'to' => $to,
            'page_size' => 50,
        ]);

        $token = $this->accessToken()['access_token'];
        $base = rtrim(config('integrations.zoom.api_base'), '/');
        $timeout = (int) config('integrations.communications.http_timeout_seconds', 12);

        $responses = Http::pool(function (\Illuminate\Http\Client\Pool $pool) use ($usersPayload, $token, $base, $query, $timeout) {
            $requests = [];

            foreach ($usersPayload['users'] as $user) {
                $userId = $user['id'] ?? null;
                if (! $userId) {
                    continue;
                }

                $requests[] = $pool->as((string) $userId)
                    ->withToken($token)
                    ->acceptJson()
                    ->timeout($timeout)
                    ->connectTimeout(5)
                    ->get($base.'/users/'.$userId.'/recordings', $query);
            }

            return $requests;
        });

        foreach ($responses as $response) {
            if ($response instanceof \Illuminate\Http\Client\Response && $response->successful()) {
                foreach ($this->mapMeetingsToRecordings($response->json('meetings') ?? []) as $recording) {
                    $recordings[$recording['id']] = $recording;
                }

                continue;
            }

            if ($response instanceof \Illuminate\Http\Client\Response && ! $warning) {
                $warning = $this->humanizeError('Zoom API error: '.$response->body());
            }
        }

        $sorted = collect($recordings)
            ->sortByDesc('start_time')
            ->values()
            ->take(200)
            ->all();

        return [
            'recordings' => $sorted,
            'next_page_token' => null,
            'warning' => $warning,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $meetings
     * @return array<int, array<string, mixed>>
     */
    protected function mapMeetingsToRecordings(array $meetings): array
    {
        $recordings = [];

        foreach ($meetings as $meeting) {
            foreach ($meeting['recording_files'] ?? [] as $file) {
                $id = (string) ($file['id'] ?? Str::uuid()->toString());
                $downloadUrl = $file['download_url'] ?? null;
                $playUrl = $file['play_url'] ?? null;

                if (filled($downloadUrl) || filled($playUrl)) {
                    $this->cacheRecordingMeta($id, 'cloud', $downloadUrl, $playUrl);
                }

                $recordings[] = [
                    'id' => $id,
                    'source' => 'cloud',
                    'topic' => $meeting['topic'] ?? 'Recording',
                    'host' => $meeting['host_email'] ?? '—',
                    'start_time' => $meeting['start_time'] ?? $file['recording_start'] ?? null,
                    'duration' => $file['recording_end'] ?? $meeting['duration'] ?? 0,
                    'file_type' => $file['file_type'] ?? '—',
                    'has_media' => filled($downloadUrl) || filled($playUrl),
                ];
            }
        }

        return $recordings;
    }

    protected function mapPhoneRecordingRow(array $row): array
    {
        $id = (string) ($row['id'] ?? Str::uuid()->toString());
        $downloadUrl = filled($row['download_url'] ?? null)
            ? $this->normalizeZoomMediaUrl((string) $row['download_url'])
            : $this->phoneRecordingDownloadUrl($id);

        $this->cachePhoneRecordingAliases($row, $id, $downloadUrl);

        $caller = $row['caller_name'] ?? $row['caller_number'] ?? 'Caller';
        $callee = $row['callee_name'] ?? $row['callee_number'] ?? 'Callee';

        return [
            'id' => $id,
            'source' => 'phone',
            'topic' => "Phone call · {$caller} → {$callee}",
            'host' => $caller,
            'start_time' => $row['date_time'] ?? $row['start_time'] ?? null,
            'duration' => (int) ($row['duration'] ?? 0),
            'file_type' => 'Phone',
            'has_media' => true,
            'call_history_uuid' => $row['call_history_uuid'] ?? null,
            'call_element_id' => $row['call_element_id'] ?? null,
            'call_log_id' => isset($row['call_log_id']) ? (string) $row['call_log_id'] : null,
            'call_id' => isset($row['call_id']) ? (string) $row['call_id'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function cachePhoneRecordingAliases(array $row, string $fileId, string $downloadUrl): void
    {
        $this->cacheRecordingMeta($fileId, 'phone', $downloadUrl, null, $fileId);

        foreach (['call_history_uuid', 'call_element_id', 'call_log_id', 'call_id'] as $field) {
            if (filled($row[$field] ?? null)) {
                $this->cacheRecordingMeta((string) $row[$field], 'phone', $downloadUrl, null, $fileId);
            }
        }
    }

    protected function mapVoiceMailRow(array $row): array
    {
        $id = (string) ($row['id'] ?? Str::uuid()->toString());
        $fileId = (string) ($row['file_id'] ?? $row['id'] ?? $id);
        $downloadUrl = $row['download_url'] ?? null;

        if (filled($downloadUrl)) {
            $this->cacheVoicemailMeta($fileId, (string) $downloadUrl);
        }

        $caller = $row['caller_name'] ?? $row['caller_number'] ?? 'Unknown caller';
        $owner = is_array($row['owner'] ?? null) ? $row['owner'] : [];

        return [
            'id' => $id,
            'file_id' => $fileId,
            'caller' => $caller,
            'caller_number' => $row['caller_number'] ?? null,
            'callee' => $row['callee_name']
                ?? ($owner['name'] ?? null)
                ?? $row['owner_name']
                ?? $row['callee_number']
                ?? '—',
            'date_time' => $row['date_time'] ?? $row['create_time'] ?? null,
            'duration' => (int) ($row['duration'] ?? 0),
            'status' => $row['status'] ?? 'unknown',
            'transcription' => $row['transcription'] ?? $row['transcript'] ?? null,
            'has_media' => filled($downloadUrl) || filled($fileId),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function mapSmsSessionRow(array $row): array
    {
        $participants = collect($row['participants'] ?? []);
        $owner = $participants->first(fn ($p) => ($p['is_session_owner'] ?? false) === true)
            ?? $participants->first();
        $other = $participants->first(fn ($p) => ($p['is_session_owner'] ?? false) !== true);

        $ownerPhone = $owner['phone_number'] ?? null;
        $otherPhone = $other['phone_number'] ?? null;
        $label = $other['display_name']
            ?? $otherPhone
            ?? $owner['display_name']
            ?? $ownerPhone
            ?? 'SMS thread';

        return [
            'session_id' => $row['session_id'] ?? null,
            'label' => $label,
            'owner_phone' => $ownerPhone,
            'other_phone' => $otherPhone,
            'session_type' => $row['session_type'] ?? 'user',
            'last_access_time' => $row['last_access_time'] ?? null,
            'participants' => $participants->map(fn ($p) => [
                'name' => $p['display_name'] ?? '—',
                'phone' => $p['phone_number'] ?? null,
                'is_owner' => (bool) ($p['is_session_owner'] ?? false),
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function mapSmsMessageRow(array $row): array
    {
        $direction = strtolower((string) ($row['direction'] ?? $row['message_type'] ?? 'unknown'));
        if ($direction === 'unknown' && isset($row['sender'])) {
            $direction = 'outbound';
        }

        return [
            'id' => $row['message_id'] ?? $row['id'] ?? Str::uuid()->toString(),
            'message' => $row['message'] ?? $row['content'] ?? $row['text'] ?? '',
            'date_time' => $row['date_time'] ?? $row['send_time'] ?? null,
            'direction' => $direction,
            'delivery_status' => $row['delivery_status'] ?? $row['status'] ?? 'unknown',
            'from' => $row['sender']['phone_number'] ?? $row['from_number'] ?? $row['from'] ?? null,
            'to' => collect($row['to_members'] ?? $row['receivers'] ?? [])->pluck('phone_number')->filter()->first()
                ?? $row['to_number']
                ?? null,
            'attachments' => collect($row['attachments'] ?? [])->map(fn ($a) => [
                'name' => $a['name'] ?? 'attachment',
                'type' => $a['type'] ?? 'OTHER',
                'download_url' => $a['download_url'] ?? null,
            ])->values()->all(),
        ];
    }

    /**
     * @return array{download_url: string|null, content_type: string|null}
     */
    protected function resolveVoicemailMeta(string $fileId): array
    {
        $cached = Cache::get($this->voicemailCacheKey($fileId));

        if (is_array($cached) && filled($cached['download_url'] ?? null)) {
            return $cached;
        }

        return [
            'download_url' => rtrim(config('integrations.zoom.api_base'), '/').'/phone/voice_mails/download/'.$fileId,
            'content_type' => 'audio/mpeg',
        ];
    }

    protected function cacheVoicemailMeta(string $fileId, string $downloadUrl): void
    {
        Cache::put($this->voicemailCacheKey($fileId), [
            'download_url' => $this->normalizeZoomMediaUrl($downloadUrl),
            'content_type' => 'audio/mpeg',
        ], now()->addHours(2));
    }

    protected function voicemailCacheKey(string $fileId): string
    {
        return 'zoom.voicemail.'.$fileId;
    }

    protected function isVoicemailScopeError(string $message): bool
    {
        $zoomMessage = strtolower($this->parseZoomMessage($message));

        return str_contains($zoomMessage, 'voicemail')
            && (str_contains($zoomMessage, 'scope') || str_contains($zoomMessage, 'scopes'));
    }

    protected function isSmsScopeError(string $message): bool
    {
        $zoomMessage = strtolower($this->parseZoomMessage($message));

        return (str_contains($zoomMessage, 'sms') || str_contains($zoomMessage, 'phone_sms'))
            && (str_contains($zoomMessage, 'scope') || str_contains($zoomMessage, 'scopes'));
    }

    protected function isCallQueueScopeError(string $message): bool
    {
        $zoomMessage = strtolower($this->parseZoomMessage($message));

        return str_contains($zoomMessage, 'call_queue')
            && (str_contains($zoomMessage, 'scope') || str_contains($zoomMessage, 'scopes'));
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function clampDateRange(string $from, string $to, int $maxDays = 30): array
    {
        $fromDate = \Carbon\Carbon::parse($from)->startOfDay();
        $toDate = \Carbon\Carbon::parse($to)->endOfDay();

        if ($toDate->lt($fromDate)) {
            [$fromDate, $toDate] = [$toDate->copy()->startOfDay(), $fromDate->copy()->endOfDay()];
        }

        if ($fromDate->diffInDays($toDate) > $maxDays) {
            $fromDate = $toDate->copy()->subDays($maxDays)->startOfDay();
        }

        return [$fromDate->toDateString(), $toDate->toDateString()];
    }

    /**
     * @param  array<int, string>  $warnings
     */
    protected function shouldUsePerUserFallback(array $warnings): bool
    {
        if (! config('integrations.communications.user_fallback', true)) {
            return false;
        }

        return $warnings !== [] || config('integrations.communications.user_fallback_on_empty', true);
    }

    /**
     * @return array{source: string, download_url: string|null, play_url: string|null, content_type: string|null}
     */
    protected function resolveRecordingMeta(string $source, string $recordingId): array
    {
        $cached = Cache::get($this->recordingCacheKey($recordingId));

        if (is_array($cached) && $this->isValidRecordingCacheEntry($recordingId, $cached)) {
            return $cached;
        }

        Cache::forget($this->recordingCacheKey($recordingId));

        if ($source === 'phone') {
            $resolved = $this->resolvePhoneRecordingMeta($recordingId);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        throw new \RuntimeException('Recording not found or expired. Refresh the recordings list and try again.');
    }

    /**
     * @return array{source: string, download_url: string|null, play_url: string|null, content_type: string|null, file_id: string|null}|null
     */
    protected function resolvePhoneRecordingMeta(string $recordingOrCallId): ?array
    {
        foreach ($this->fetchPhoneRecordingRowsForCall($recordingOrCallId) as $row) {
            $meta = $this->storePhoneRecordingFromRow($row, $recordingOrCallId);
            if ($meta !== null) {
                return $meta;
            }
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function fetchPhoneRecordingRowsForCall(string $callReferenceId): array
    {
        $rows = $this->requestPhoneRecordingList('/phone/call_logs/'.$callReferenceId.'/recordings');

        foreach (['/phone/call_element/'.$callReferenceId, '/phone/call_history_detail/'.$callReferenceId] as $path) {
            $detail = $this->requestPhoneDetail($path);
            if ($detail !== null) {
                $rows = array_merge($rows, $this->extractRecordingRowsFromDetail($detail));
            }
        }

        $history = $this->requestPhoneCallHistory($callReferenceId);
        if ($history !== null) {
            $rows = array_merge($rows, $this->extractRecordingRowsFromDetail($history));

            foreach (['call_id', 'call_log_id', 'call_element_id'] as $field) {
                $relatedId = $history[$field] ?? null;
                if (! filled($relatedId) || (string) $relatedId === $callReferenceId) {
                    continue;
                }

                $rows = array_merge($rows, $this->requestPhoneRecordingList('/phone/call_logs/'.$relatedId.'/recordings'));

                if ($field === 'call_element_id') {
                    $rows = array_merge(
                        $rows,
                        $this->extractRecordingRowsFromDetail($this->requestPhoneDetail('/phone/call_element/'.$relatedId))
                    );
                }
            }
        }

        if ($rows === []) {
            $rows = $this->findPhoneRecordingsInAccountList($callReferenceId);
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function requestPhoneDetail(string $path): ?array
    {
        try {
            return $this->request('get', $path);
        } catch (\RuntimeException) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<int, array<string, mixed>>
     */
    protected function extractRecordingRowsFromDetail(array $detail): array
    {
        $rows = [];

        if (filled($detail['recording_id'] ?? null)) {
            $rows[] = [
                'id' => $detail['recording_id'],
                'download_url' => $detail['download_url'] ?? null,
            ];
        }

        if (filled($detail['download_url'] ?? null) && filled($detail['id'] ?? null)) {
            $rows[] = [
                'id' => $detail['id'],
                'download_url' => $detail['download_url'],
            ];
        }

        foreach ($detail['recordings'] ?? [] as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        foreach ($detail['record_elements'] ?? $detail['call_elements'] ?? [] as $element) {
            if (! is_array($element)) {
                continue;
            }

            if (filled($element['recording_id'] ?? null)) {
                $rows[] = [
                    'id' => $element['recording_id'],
                    'download_url' => $element['download_url'] ?? null,
                ];
            }
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function findPhoneRecordingsInAccountList(string $referenceId): array
    {
        $matches = [];
        $pageToken = null;
        $pages = 0;

        try {
            do {
                $payload = $this->listPhoneRecordings([
                    'from' => now()->subDays(90)->toDateString(),
                    'to' => now()->toDateString(),
                    'per_page' => 50,
                    'page_token' => $pageToken,
                ]);

                foreach ($payload['recordings'] ?? [] as $recording) {
                    if ($this->recordingReferenceMatches($recording, $referenceId)) {
                        $matches[] = [
                            'id' => $recording['id'],
                            'download_url' => $this->phoneRecordingDownloadUrl((string) $recording['id']),
                        ];
                    }
                }

                $pageToken = $payload['next_page_token'] ?? null;
                $pages++;
            } while ($pageToken && $pages < 3 && $matches === []);
        } catch (\RuntimeException) {
            return [];
        }

        return $matches;
    }

    /**
     * @param  array<string, mixed>  $recording
     */
    protected function recordingReferenceMatches(array $recording, string $referenceId): bool
    {
        foreach (['id', 'call_history_uuid', 'call_element_id', 'call_log_id', 'call_id'] as $field) {
            $candidate = (string) ($recording[$field] ?? '');
            if ($candidate === '') {
                continue;
            }

            if ($candidate === $referenceId || $this->compactZoomReferenceId($candidate) === $this->compactZoomReferenceId($referenceId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function requestPhoneRecordingList(string $path): array
    {
        try {
            $response = $this->request('get', $path);

            return $response['recordings'] ?? [];
        } catch (\RuntimeException) {
            return [];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function requestPhoneCallHistory(string $id): ?array
    {
        try {
            return $this->request('get', '/phone/call_history/'.$id);
        } catch (\RuntimeException) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{source: string, download_url: string|null, play_url: string|null, content_type: string|null, file_id: string|null}|null
     */
    protected function storePhoneRecordingFromRow(array $row, string $aliasId): ?array
    {
        $fileId = (string) ($row['id'] ?? $row['recording_id'] ?? '');
        if ($fileId === '') {
            return null;
        }

        $downloadUrl = filled($row['download_url'] ?? null)
            ? $this->normalizeZoomMediaUrl((string) $row['download_url'])
            : $this->phoneRecordingDownloadUrl($fileId);

        $this->cacheRecordingMeta($fileId, 'phone', $downloadUrl, null, $fileId);
        $this->cacheRecordingMeta($aliasId, 'phone', $downloadUrl, null, $fileId);

        return Cache::get($this->recordingCacheKey($aliasId));
    }

    /**
     * @param  array{source?: string, download_url?: string|null, play_url?: string|null, content_type?: string|null, file_id?: string|null}  $cached
     */
    protected function isValidRecordingCacheEntry(string $recordingId, array $cached): bool
    {
        $url = (string) ($cached['download_url'] ?? $cached['play_url'] ?? '');
        if ($url === '') {
            return false;
        }

        $fileId = (string) ($cached['file_id'] ?? $this->extractPhoneRecordingFileId($url) ?? '');

        return ! ($fileId === $recordingId && $this->looksLikeCallHistoryId($recordingId));
    }

    protected function looksLikeCallHistoryId(string $id): bool
    {
        return (bool) preg_match('/^\d{8}-[0-9a-f-]{36}$/i', $id);
    }

    protected function extractPhoneRecordingFileId(string $url): ?string
    {
        if (preg_match('#/phone/recording/download/([^/?]+)#', $url, $matches)) {
            return $matches[1];
        }

        return null;
    }

    protected function phoneRecordingDownloadUrl(string $fileId): string
    {
        return rtrim(config('integrations.zoom.api_base'), '/').'/phone/recording/download/'.$fileId;
    }

    protected function normalizeZoomMediaUrl(string $url): string
    {
        return str_replace(
            ['https://zoom.us/v2/', 'http://zoom.us/v2/'],
            'https://api.zoom.us/v2/',
            $url
        );
    }

    protected function cacheRecordingMeta(string $id, string $source, ?string $downloadUrl, ?string $playUrl = null, ?string $fileId = null): void
    {
        if (! filled($downloadUrl) && ! filled($playUrl)) {
            return;
        }

        $normalizedDownload = $downloadUrl ? $this->normalizeZoomMediaUrl($downloadUrl) : null;
        $normalizedPlay = $playUrl ? $this->normalizeZoomMediaUrl($playUrl) : null;

        Cache::put($this->recordingCacheKey($id), [
            'source' => $source,
            'download_url' => $normalizedDownload,
            'play_url' => $normalizedPlay,
            'content_type' => 'audio/mpeg',
            'file_id' => $fileId ?? $this->extractPhoneRecordingFileId((string) ($normalizedDownload ?? $normalizedPlay ?? '')),
        ], now()->addHours(2));
    }

    protected function recordingCacheKey(string $id): string
    {
        return 'zoom.recording.'.$id;
    }

    protected function isPhoneRecordingScopeError(string $message): bool
    {
        $zoomMessage = strtolower($this->parseZoomMessage($message));

        return str_contains($zoomMessage, 'phone:')
            && str_contains($zoomMessage, 'recording')
            && (str_contains($zoomMessage, 'scope') || str_contains($zoomMessage, 'scopes'));
    }

    protected function isRecordingScopeError(string $message): bool
    {
        $zoomMessage = strtolower($this->parseZoomMessage($message));

        return str_contains($zoomMessage, 'cloud_recording')
            && (str_contains($zoomMessage, 'scope') || str_contains($zoomMessage, 'scopes'));
    }

    protected function isCallLogScopeError(string $message): bool
    {
        $zoomMessage = strtolower($this->parseZoomMessage($message));

        return str_contains($zoomMessage, 'phone:')
            && (str_contains($zoomMessage, 'scope') || str_contains($zoomMessage, 'scopes'));
    }

    protected function isPhoneUnavailableError(string $message): bool
    {
        $zoomMessage = strtolower($this->parseZoomMessage($message));

        return str_contains($zoomMessage, 'phone has not been enabled')
            || str_contains($zoomMessage, 'zoom phone has not been enabled')
            || str_contains($zoomMessage, 'phone is not enabled')
            || str_contains($zoomMessage, '"code":124')
            || str_contains($zoomMessage, '"code":2031');
    }

    /**
     * @param  array<int, string>  $warnings
     */
    protected function phoneFeaturesUnavailable(array $warnings): bool
    {
        return collect($warnings)->contains(function (string $warning) {
            $lower = strtolower($warning);

            return str_contains($lower, 'zoom phone')
                || str_contains($lower, 'phone has not been enabled');
        });
    }

    protected function httpTimeout(): int
    {
        return (int) config('integrations.communications.http_timeout_seconds', 12);
    }

    protected function formatCallHistoryParty(string $side, array $row): string
    {
        $prefix = $side === 'caller' ? 'caller' : 'callee';
        $parts = array_filter([
            $row["{$prefix}_name"] ?? null,
            $row["{$prefix}_did_number"] ?? null,
            isset($row["{$prefix}_ext_number"]) ? 'Ext. '.$row["{$prefix}_ext_number"] : null,
        ]);

        return $parts !== [] ? implode(', ', $parts) : '—';
    }

    /**
     * @return array<string, mixed>
     */
    public function normalizeCallLog(array $row): array
    {
        if (isset($row['call_history_uuid']) || isset($row['caller_did_number'])) {
            $fromPhone = filled($row['caller_did_number'] ?? null)
                ? $this->normalizePhone((string) $row['caller_did_number'])
                : null;
            $toPhone = filled($row['callee_did_number'] ?? null)
                ? $this->normalizePhone((string) $row['callee_did_number'])
                : null;
            $recordingStatus = strtolower((string) ($row['recording_status'] ?? ''));

            return [
                'id' => $row['call_history_uuid'] ?? $row['call_id'] ?? Str::uuid()->toString(),
                'direction' => strtolower($row['direction'] ?? 'unknown'),
                'from' => $this->formatCallHistoryParty('caller', $row),
                'from_phone' => $fromPhone,
                'to' => $this->formatCallHistoryParty('callee', $row),
                'to_phone' => $toPhone,
                'start_time' => $row['start_time'] ?? null,
                'duration' => (int) ($row['duration'] ?? 0),
                'result' => $row['call_result'] ?? '—',
                'recording' => $recordingStatus !== '' && $recordingStatus !== 'non_recorded' ? 'Yes' : '—',
                'raw' => $row,
            ];
        }

        return [
            'id' => $row['id'] ?? Str::uuid()->toString(),
            'direction' => strtolower($row['direction'] ?? 'unknown'),
            'from' => $this->formatParty($row['caller'] ?? $row['from'] ?? null),
            'from_phone' => $this->partyPhone($row['caller'] ?? $row['from'] ?? null),
            'to' => $this->formatParty($row['callee'] ?? $row['to'] ?? null),
            'to_phone' => $this->partyPhone($row['callee'] ?? $row['to'] ?? null),
            'start_time' => $row['date_time'] ?? $row['start_time'] ?? null,
            'duration' => (int) ($row['duration'] ?? 0),
            'result' => $row['call_result'] ?? $row['result'] ?? '—',
            'recording' => ($row['has_recording'] ?? false) ? 'Yes' : '—',
            'raw' => $row,
        ];
    }

    public function maskedSecret(): string
    {
        $secret = (string) config('integrations.zoom.client_secret');

        if ($secret === '') {
            return '—';
        }

        return str_repeat('•', max(8, strlen($secret) - 4)).substr($secret, -4);
    }

    public function webhookSecret(): ?string
    {
        $secret = config('integrations.zoom.webhook_secret');

        return filled($secret) ? (string) $secret : null;
    }

    public function accountId(): ?string
    {
        $id = config('integrations.zoom.account_id');

        return filled($id) ? (string) $id : null;
    }

    public function clientId(): ?string
    {
        $id = config('integrations.zoom.client_id');

        return filled($id) ? (string) $id : null;
    }

    /**
     * @return array<string, string>
     */
    public function requiredScopes(): array
    {
        return config('integrations.zoom.required_scopes', []);
    }

    public function clearAccessTokenCache(): void
    {
        Cache::forget('zoom.s2s.access_token');
    }

    public function humanizeError(string $message): string
    {
        return $this->friendlyError($message);
    }

    /**
     * @return array{access_token: string, expires_at: string}
     */
    protected function accessToken(): array
    {
        return Cache::remember('zoom.s2s.access_token', now()->addMinutes(55), function () {
            $accountId = config('integrations.zoom.account_id');
            $clientId = config('integrations.zoom.client_id');
            $clientSecret = config('integrations.zoom.client_secret');

            $response = Http::withBasicAuth($clientId, $clientSecret)
                ->asForm()
                ->post(config('integrations.zoom.oauth_url'), [
                    'grant_type' => 'account_credentials',
                    'account_id' => $accountId,
                ]);

            if (! $response->successful()) {
                throw new \RuntimeException('Zoom authentication failed: '.$response->body());
            }

            $data = $response->json();
            $expiresIn = (int) ($data['expires_in'] ?? 3600);

            return [
                'access_token' => $data['access_token'],
                'expires_at' => now()->addSeconds($expiresIn)->toIso8601String(),
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function request(string $method, string $path, array $query = [], bool $retried = false): array
    {
        $token = $this->accessToken();
        $query = array_filter($query, fn ($value) => $value !== null && $value !== '');

        $response = Http::withToken($token['access_token'])
            ->acceptJson()
            ->timeout($this->httpTimeout())
            ->connectTimeout(5)
            ->{$method}(rtrim(config('integrations.zoom.api_base'), '/').$path, $query);

        if (! $response->successful()) {
            $body = $response->body();

            if (! $retried && $this->isStaleScopeTokenError($body)) {
                $this->clearAccessTokenCache();
                Cache::forget('zoom.connection.diagnostics');
                try {
                    app(CommunicationsDataService::class)->bustCache();
                } catch (\Throwable) {
                    //
                }

                return $this->request($method, $path, $query, true);
            }

            throw new \RuntimeException('Zoom API error: '.$body);
        }

        return $response->json();
    }

    protected function isStaleScopeTokenError(string $body): bool
    {
        return str_contains($body, '4711')
            && (str_contains($body, 'scopes') || str_contains($body, 'scope'));
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    protected function requestJson(string $method, string $path, array $query = [], array $body = [], bool $retried = false): array
    {
        $token = $this->accessToken();
        $query = array_filter($query, fn ($value) => $value !== null && $value !== '');
        $url = rtrim(config('integrations.zoom.api_base'), '/').$path;

        if ($query !== []) {
            $url .= '?'.http_build_query($query);
        }

        $response = Http::withToken($token['access_token'])
            ->acceptJson()
            ->timeout($this->httpTimeout())
            ->connectTimeout(5)
            ->{$method}($url, $body);

        if (! $response->successful()) {
            $responseBody = $response->body();

            if (! $retried && $this->isStaleScopeTokenError($responseBody)) {
                $this->clearAccessTokenCache();

                return $this->requestJson($method, $path, $query, $body, true);
            }

            throw new \RuntimeException('Zoom API error: '.$responseBody);
        }

        return $response->json();
    }

    /**
     * @param  array{sender: array<string, mixed>, to_members: array<int, array<string, mixed>>, message: string, session_id?: string|null}  $payload
     * @return array<string, mixed>
     */
    public function sendSmsMessage(array $payload): array
    {
        return $this->requestJson('post', '/phone/sms/messages', [], $payload);
    }

    protected function parseZoomMessage(string $raw): string
    {
        $body = preg_replace('/^Zoom (API error|authentication failed):\s*/', '', $raw) ?? $raw;
        $json = json_decode($body, true);

        if (is_array($json) && filled($json['message'] ?? null)) {
            return (string) $json['message'];
        }

        return $body;
    }

    protected function extractMissingScopes(string $message): ?string
    {
        if (preg_match('/does not contain scopes:\[([^\]]+)\]/', $message, $matches)) {
            return $matches[1];
        }

        if (preg_match('/does not contain required scopes[^[]*\[([^\]]+)\]/', $message, $matches)) {
            return $matches[1];
        }

        return null;
    }

    protected function formatParty(mixed $party): string
    {
        if (is_string($party)) {
            return $party;
        }

        if (! is_array($party)) {
            return '—';
        }

        $parts = array_filter([
            $party['name'] ?? null,
            $party['phone_number'] ?? $party['number'] ?? null,
            isset($party['extension_number']) ? 'Ext. '.$party['extension_number'] : null,
        ]);

        return $parts !== [] ? implode(', ', $parts) : '—';
    }

    protected function partyPhone(mixed $party): ?string
    {
        if (is_string($party)) {
            return $this->normalizePhone($party);
        }

        if (! is_array($party)) {
            return null;
        }

        $phone = $party['phone_number'] ?? $party['number'] ?? null;

        return $phone ? $this->normalizePhone((string) $phone) : null;
    }

    protected function normalizePhone(string $phone): string
    {
        return preg_replace('/[^\d+]/', '', $phone) ?: $phone;
    }

    protected function friendlyError(string $message): string
    {
        $zoomMessage = $this->parseZoomMessage($message);

        if (str_contains($message, 'cURL error 60') || str_contains($message, 'unable to get local issuer certificate')) {
            return 'HTTPS certificate verification failed. Ensure storage/certs/cacert.pem exists or set CURL_CA_BUNDLE in .env.';
        }

        if ($scopes = $this->extractMissingScopes($zoomMessage)) {
            return "Your Zoom Server-to-Server app is missing scope(s): {$scopes}. "
                .'Add them under Scopes in the Zoom Marketplace app, save, then clear the cached token with: php artisan zoom:clear-token';
        }

        if (str_contains($zoomMessage, '124')) {
            return 'Zoom Phone is not enabled on this Zoom account. Inbox can still show Zoom users; enable Zoom Phone for calls, SMS, voicemail, and recordings.';
        }

        if (str_contains(strtolower($zoomMessage), 'phone has not been enabled')
            || str_contains(strtolower($zoomMessage), 'zoom phone has not been enabled')) {
            return 'Zoom Phone is not enabled on this Zoom account. Inbox can still show Zoom users; enable Zoom Phone for calls, SMS, voicemail, and recordings.';
        }

        if (str_contains($zoomMessage, '4711') || str_contains(strtolower($zoomMessage), 'scope')) {
            return 'Zoom app is missing required API scopes. Check Settings → Required API scopes, then run: php artisan zoom:clear-token';
        }

        return Str::limit($zoomMessage, 220);
    }
}
