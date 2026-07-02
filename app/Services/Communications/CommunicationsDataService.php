<?php

namespace App\Services\Communications;

use App\Services\Integrations\ZoomApiService;
use Illuminate\Support\Facades\Cache;

class CommunicationsDataService
{
    public function __construct(
        protected ZoomApiService $zoom,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function users(): array
    {
        return Cache::remember($this->versionedKey('zoom.users'), $this->ttl(), function () {
            $users = [];
            $pageToken = null;
            $pages = 0;

            do {
                $payload = $this->zoom->listUsers([
                    'page_token' => $pageToken,
                    'per_page' => 50,
                ]);

                foreach ($payload['users'] as $user) {
                    $users[] = $user;
                }

                $pageToken = $payload['next_page_token'] ?? null;
                $pages++;
            } while ($pageToken && $pages < 2);

            return $users;
        });
    }

    /**
     * @return array{logs: array<int, array<string, mixed>>, next_page_token: string|null, warning: string|null}
     */
    public function callLogs(array $filters, int $maxPages = 1, bool $enrichRecordings = false): array
    {
        $cacheKey = $this->versionedKey('call_logs.'.md5(json_encode([
            $filters['from'] ?? null,
            $filters['to'] ?? null,
            $filters['page_token'] ?? null,
            $maxPages,
            $enrichRecordings,
        ])));

        return $this->rememberUnlessEmptyFailure($cacheKey, function () use ($filters, $maxPages, $enrichRecordings) {
            $logs = [];
            $pageToken = $filters['page_token'] ?? null;
            $pages = 0;
            $warning = null;

            do {
                $payload = $this->zoom->listCallLogs([
                    'from' => $filters['from'] ?? now()->subDays(14)->toDateString(),
                    'to' => $filters['to'] ?? now()->toDateString(),
                    'page_token' => $pageToken,
                    'per_page' => (int) ($filters['per_page'] ?? config('integrations.communications.list_page_size', 20)),
                ]);

                if (filled($payload['warning'] ?? null)) {
                    $warning = $payload['warning'];
                }

                foreach ($payload['logs'] ?? $payload['call_logs'] ?? [] as $row) {
                    $logs[] = $this->zoom->normalizeCallLog($row);
                }

                $pageToken = $payload['next_page_token'] ?? null;
                $pages++;
            } while ($pageToken && $pages < $maxPages);

            return [
                'logs' => $this->enrichLogsWithRecordingFlags($logs, $filters, $enrichRecordings),
                'next_page_token' => $pageToken,
                'warning' => $warning,
            ];
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $logs
     * @return array<int, array<string, mixed>>
     */
    protected function enrichLogsWithRecordingFlags(array $logs, array $filters = [], bool $fetchIndex = false): array
    {
        if ($logs === [] || ! $fetchIndex) {
            return $logs;
        }

        $needsIndex = collect($logs)->contains(function (array $log) {
            $hasRecording = (bool) ($log['raw']['has_recording'] ?? false)
                || (bool) ($log['has_recording_media'] ?? false);
            $status = strtolower((string) ($log['raw']['recording_status'] ?? ''));

            return $hasRecording || ($status !== '' && $status !== 'non_recorded');
        });

        if (! $needsIndex) {
            return $logs;
        }

        $index = $this->phoneRecordingFileIndex($filters);

        return array_map(function (array $log) use ($index) {
            $hasRecording = (bool) ($log['raw']['has_recording'] ?? false)
                || (bool) ($log['has_recording_media'] ?? false);
            $status = strtolower((string) ($log['raw']['recording_status'] ?? ''));
            $isRecorded = $hasRecording || ($status !== '' && $status !== 'non_recorded');

            if (! $isRecorded) {
                return $log;
            }

            $callKey = (string) $log['id'];
            $fileId = $index['by_reference'][$callKey]
                ?? $index['by_reference'][$this->zoom->compactZoomReferenceId($callKey)]
                ?? null;

            $log['recording'] = 'Yes';
            $log['recording_source'] = 'phone';
            $log['has_recording_media'] = true;
            $log['recording_id'] = $fileId ?? $callKey;
            $log['call_reference_id'] = $callKey;

            return $log;
        }, $logs);
    }

    /**
     * @return array{by_reference: array<string, string>}
     */
    protected function phoneRecordingFileIndex(array $filters): array
    {
        $cacheKey = $this->versionedKey('phone_recording_index.'.md5(json_encode([
            $filters['from'] ?? null,
            $filters['to'] ?? null,
        ])));

        return Cache::remember($cacheKey, $this->ttl(), function () use ($filters) {
            $byReference = [];

            try {
                $pageToken = null;
                $pages = 0;

                do {
                    $payload = $this->zoom->listPhoneRecordings(array_merge($filters, [
                        'page_token' => $pageToken,
                        'per_page' => 50,
                    ]));

                    foreach ($payload['recordings'] as $recording) {
                        $fileId = (string) ($recording['id'] ?? '');
                        if ($fileId === '') {
                            continue;
                        }

                        foreach (['call_uuid', 'call_history_uuid', 'call_element_id', 'call_log_id', 'call_id', 'id'] as $field) {
                            if (filled($recording[$field] ?? null)) {
                                $reference = (string) $recording[$field];
                                $byReference[$reference] = $fileId;
                                $byReference[$this->zoom->compactZoomReferenceId($reference)] = $fileId;
                            }
                        }
                    }

                    $pageToken = $payload['next_page_token'] ?? null;
                    $pages++;
                } while ($pageToken && $pages < 3);
            } catch (\Throwable) {
                //
            }

            return ['by_reference' => $byReference];
        });
    }

    /**
     * @return array{recordings: array<int, array<string, mixed>>, next_page_token: string|null, warnings: array<int, string>}
     */
    public function recordings(array $filters): array
    {
        $cacheKey = $this->versionedKey('recordings.'.md5(json_encode([
            $filters['from'] ?? null,
            $filters['to'] ?? null,
            $filters['page_token'] ?? null,
        ])));

        return $this->rememberUnlessEmptyFailure($cacheKey, function () use ($filters) {
            $payload = $this->zoom->listRecordings($filters);

            return [
                'recordings' => $payload['recordings'] ?? [],
                'next_page_token' => $payload['next_page_token'] ?? null,
                'warnings' => $payload['warnings'] ?? [],
            ];
        });
    }

    /**
     * @return array{voice_mails: array<int, array<string, mixed>>, next_page_token: string|null, warning: string|null}
     */
    public function voiceMails(array $filters): array
    {
        $cacheKey = $this->versionedKey('voicemails.'.md5(json_encode([
            $filters['from'] ?? null,
            $filters['to'] ?? null,
            $filters['status'] ?? null,
            $filters['page_token'] ?? null,
        ])));

        return $this->rememberUnlessEmptyFailure($cacheKey, fn () => $this->zoom->listVoiceMails($filters));
    }

    /**
     * @return array{sessions: array<int, array<string, mixed>>, next_page_token: string|null, warning: string|null}
     */
    public function smsSessions(array $filters): array
    {
        $cacheKey = $this->versionedKey('sms_sessions.'.md5(json_encode([
            $filters['page_token'] ?? null,
        ])));

        return $this->rememberUnlessEmptyFailure($cacheKey, fn () => $this->zoom->listSmsSessions($filters));
    }

    /**
     * @return array{messages: array<int, array<string, mixed>>, next_page_token: string|null}
     */
    public function smsMessages(string $sessionId, array $filters = []): array
    {
        $cacheKey = $this->versionedKey('sms_messages.'.md5($sessionId.json_encode([
            $filters['page_token'] ?? null,
        ])));

        return Cache::remember($cacheKey, $this->ttl(), fn () => $this->zoom->getSmsSessionMessages($sessionId, $filters));
    }

    /**
     * @return array{queues: array<int, array<string, mixed>>, next_page_token: string|null, warning: string|null}
     */
    public function callQueues(array $filters = []): array
    {
        return Cache::remember($this->versionedKey('call_queues'), $this->ttl(), fn () => $this->zoom->listCallQueues($filters));
    }

    /**
     * @return array{channels: array<int, array<string, mixed>>, next_page_token: string|null, warning: string|null}
     */
    public function teamChatChannels(array $filters = []): array
    {
        $cacheKey = $this->versionedKey('team_chat_channels.'.md5(json_encode([
            $filters['page_token'] ?? null,
        ])));

        return Cache::remember($cacheKey, $this->ttl(), fn () => $this->zoom->listTeamChatChannels($filters));
    }

    /**
     * @return array{messages: array<int, array<string, mixed>>, next_page_token: string|null}
     */
    public function teamChatMessages(string $ownerUserId, array $filters = []): array
    {
        $cacheKey = $this->versionedKey('team_chat_messages.'.md5($ownerUserId.json_encode([
            $filters['to_channel'] ?? null,
            $filters['to_contact'] ?? null,
            $filters['page_token'] ?? null,
        ])));

        return Cache::remember($cacheKey, $this->ttl(), fn () => $this->zoom->getTeamChatMessages($ownerUserId, $filters));
    }

    /**
     * @return array{users: array<int, array<string, mixed>>, next_page_token: string|null, warning: string|null}
     */
    public function phoneUsers(array $filters = []): array
    {
        return $this->rememberUnlessEmptyFailure(
            $this->versionedKey('phone_users'),
            fn () => $this->zoom->listPhoneUsers($filters),
        );
    }

    /**
     * @return array<int, string>
     */
    public function recentDialNumbers(array $filters, int $limit = 12, ?array $logs = null): array
    {
        if ($logs === null) {
            $logs = $this->callLogs($filters, 1)['logs'];
        }

        return collect($logs)
            ->flatMap(function (array $log) {
                return array_filter([
                    $log['from_phone'] ?? null,
                    $log['to_phone'] ?? null,
                ]);
            })
            ->unique()
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $logs
     * @return array<string, int|float>
     */
    public function callStatsFromLogs(array $logs): array
    {
        return [
            'total' => count($logs),
            'inbound' => collect($logs)->where('direction', 'inbound')->count(),
            'outbound' => collect($logs)->where('direction', 'outbound')->count(),
            'recorded' => collect($logs)->filter(fn ($log) => ($log['recording'] ?? '') === 'Yes')->count(),
            'missed' => collect($logs)->filter(function ($log) {
                $result = strtolower((string) ($log['result'] ?? ''));

                return str_contains($result, 'miss') || str_contains($result, 'no answer');
            })->count(),
            'total_duration' => (int) collect($logs)->sum('duration'),
        ];
    }

    /**
     * @return array<string, int|float>
     */
    public function callStats(array $filters): array
    {
        return $this->callStatsFromLogs($this->callLogs($filters, 1)['logs']);
    }

    protected function ttl(): \DateTimeInterface
    {
        return now()->addMinutes((int) config('integrations.communications.cache_ttl_minutes', 3));
    }

    protected function versionedKey(string $suffix): string
    {
        $version = (int) Cache::get('communications.cache_version', 1);

        return "communications.v{$version}.{$suffix}";
    }

    public function bustCache(): void
    {
        $version = (int) Cache::get('communications.cache_version', 1);
        Cache::forever('communications.cache_version', $version + 1);
    }

    /**
     * @template T of array<string, mixed>
     * @param  callable(): T  $callback
     * @return T
     */
    protected function rememberUnlessEmptyFailure(string $cacheKey, callable $callback): array
    {
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        /** @var T $value */
        $value = $callback();

        if ($this->shouldCachePayload($value)) {
            Cache::put($cacheKey, $value, $this->ttl());
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function shouldCachePayload(array $payload): bool
    {
        $primaryCount = count(
            $payload['logs']
            ?? $payload['voice_mails']
            ?? $payload['sessions']
            ?? $payload['users']
            ?? $payload['recordings']
            ?? $payload['channels']
            ?? []
        );

        if ($primaryCount > 0) {
            return true;
        }

        if (filled($payload['warning'] ?? null)) {
            return false;
        }

        if (! empty($payload['warnings'])) {
            return false;
        }

        // Do not cache empty phone/channel payloads — avoids stale blanks after integration fixes.
        return false;
    }
}
