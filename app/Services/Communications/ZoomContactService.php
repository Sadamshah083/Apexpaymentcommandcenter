<?php

namespace App\Services\Communications;

use App\Services\Integrations\ZoomApiService;
use Carbon\Carbon;
use Illuminate\Support\Str;

class ZoomContactService
{
    public function __construct(
        protected ZoomApiService $zoom,
        protected CommunicationsDataService $data,
    ) {}

    /**
     * @return array{contacts: array<int, array<string, mixed>>, call_logs: array<int, array<string, mixed>>, error: string|null, next_page_token: string|null}
     */
    public function buildIndexPayload(array $filters = []): array
    {
        if (! $this->zoom->isConfigured()) {
            return ['contacts' => [], 'call_logs' => [], 'error' => 'Zoom is not configured.', 'next_page_token' => null];
        }

        $users = [];
        $callLogs = [];
        $errors = [];
        $nextPageToken = null;

        try {
            $users = $this->data->users();
        } catch (\Throwable $e) {
            $errors[] = $this->zoom->humanizeError($e->getMessage());
        }

        try {
            $payload = $this->data->callLogs($filters, (int) config('integrations.communications.list_max_pages', 1));
            $callLogs = $payload['logs'];
            $nextPageToken = $payload['next_page_token'];
        } catch (\Throwable $e) {
            $errors[] = $this->zoom->humanizeError($e->getMessage());
        }

        if ($users === [] && $callLogs === [] && $errors !== []) {
            return [
                'contacts' => [],
                'call_logs' => [],
                'error' => implode(' ', $errors),
                'next_page_token' => null,
            ];
        }

        return [
            'contacts' => $this->mergeContacts($users, $callLogs, $filters),
            'call_logs' => $callLogs,
            'error' => $errors !== [] ? implode(' ', $errors) : null,
            'next_page_token' => $nextPageToken,
        ];
    }

    /**
     * @return array{contact: array<string, mixed>|null, timeline: array<int, array<string, mixed>>, stats: array<string, mixed>, error: string|null}
     */
    public function buildShowPayload(string $contactKey, array $filters = []): array
    {
        if (! $this->zoom->isConfigured()) {
            return ['contact' => null, 'timeline' => [], 'stats' => [], 'error' => 'Zoom is not configured.'];
        }

        try {
            $users = $this->data->users();
            $callPayload = $this->data->callLogs($filters, (int) config('integrations.communications.detail_max_pages', 3));
            $contacts = $this->mergeContacts($users, $callPayload['logs'], $filters);
            $contact = collect($contacts)->firstWhere('contact_key', $contactKey);

            if (! $contact) {
                return ['contact' => null, 'timeline' => [], 'stats' => [], 'error' => 'Contact not found.'];
            }

            $voiceMails = $this->data->voiceMails($filters)['voice_mails'];
            $smsSessions = $this->data->smsSessions($filters)['sessions'];

            $timeline = $this->activityTimelineFor(
                $contact,
                $callPayload['logs'],
                $voiceMails,
                $smsSessions,
            );

            $smsSession = filled($contact['phone'])
                ? $this->findSmsSessionForPhone($contact['phone'], $smsSessions)
                : null;

            return [
                'contact' => $contact,
                'timeline' => $timeline,
                'stats' => [
                    'call_count' => collect($timeline)->where('type', 'call')->count(),
                    'sms_count' => collect($timeline)->where('type', 'sms')->count(),
                    'voicemail_count' => collect($timeline)->where('type', 'voicemail')->count(),
                    'activity_count' => count($timeline),
                    'last_call_at' => collect($timeline)->firstWhere('type', 'call')['at'] ?? null,
                    'last_activity_at' => $timeline[0]['at'] ?? null,
                ],
                'sms_session' => $smsSession,
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'contact' => null,
                'timeline' => [],
                'stats' => [],
                'error' => $this->zoom->humanizeError($e->getMessage()),
            ];
        }
    }

    public function findContact(string $contactKey, array $filters = []): ?array
    {
        $payload = $this->buildShowPayload($contactKey, $filters);

        return $payload['contact'];
    }

    /**
     * @param  array<string, mixed>  $contact
     * @param  array<int, array<string, mixed>>  $callLogs
     * @param  array<int, array<string, mixed>>  $voiceMails
     * @param  array<int, array<string, mixed>>  $smsSessions
     * @return array<int, array<string, mixed>>
     */
    public function activityTimelineFor(array $contact, array $callLogs, array $voiceMails, array $smsSessions): array
    {
        $events = collect($this->timelineFor($contact['contact_key'], $callLogs))
            ->map(fn (array $event) => array_merge($event, ['type' => 'call']));

        $phone = $contact['phone'] ?? null;

        if (filled($phone)) {
            $events = $events->merge(
                collect($voiceMails)
                    ->filter(fn (array $vm) => ($vm['caller_number'] ?? null) === $phone)
                    ->map(fn (array $vm) => [
                        'type' => 'voicemail',
                        'label' => 'Voicemail',
                        'at' => $vm['date_time'] ?? now()->toIso8601String(),
                        'detail' => ($vm['status'] ?? 'unknown').' · '.$this->formatDuration((int) ($vm['duration'] ?? 0)),
                        'direction' => 'inbound',
                        'from' => $vm['caller'] ?? $phone,
                        'to' => $vm['callee'] ?? '—',
                        'file_id' => $vm['file_id'] ?? $vm['id'] ?? null,
                        'has_media' => $vm['has_media'] ?? false,
                        'transcription' => $vm['transcription'] ?? null,
                    ])
            );

            $events = $events->merge(
                collect($smsSessions)
                    ->filter(fn (array $session) => ($session['other_phone'] ?? null) === $phone)
                    ->map(fn (array $session) => [
                        'type' => 'sms',
                        'label' => 'SMS thread',
                        'at' => $session['last_access_time'] ?? now()->toIso8601String(),
                        'detail' => $session['label'] ?? 'SMS conversation',
                        'direction' => 'inbound',
                        'from' => $session['other_phone'] ?? $phone,
                        'to' => $session['owner_phone'] ?? '—',
                        'session_id' => $session['session_id'] ?? null,
                    ])
            );
        }

        return $events
            ->sortByDesc('at')
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $sessions
     * @return array<string, mixed>|null
     */
    public function findSmsSessionForPhone(string $phone, array $sessions): ?array
    {
        return collect($sessions)->first(
            fn (array $session) => ($session['other_phone'] ?? null) === $phone
                || ($session['owner_phone'] ?? null) === $phone
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $callLogs
     * @return array<int, array<string, mixed>>
     */
    public function timelineFor(string $contactKey, array $callLogs): array
    {
        $contact = $this->parseContactKey($contactKey);

        return collect($callLogs)
            ->filter(function (array $log) use ($contact) {
                if ($contact['type'] === 'user') {
                    return str_contains(strtolower($log['from'] ?? ''), strtolower($contact['needle']))
                        || str_contains(strtolower($log['to'] ?? ''), strtolower($contact['needle']));
                }

                $phone = $contact['needle'];

                return ($log['from_phone'] ?? null) === $phone
                    || ($log['to_phone'] ?? null) === $phone;
            })
            ->map(fn (array $log) => [
                'label' => 'Call '.ucfirst($log['direction'] ?? 'unknown'),
                'at' => $log['start_time'] ?? now()->toIso8601String(),
                'detail' => ($log['result'] ?? '—').' · '.$this->formatDuration((int) ($log['duration'] ?? 0)),
                'direction' => $log['direction'] ?? 'unknown',
                'recording_id' => $log['recording_id'] ?? null,
                'call_reference_id' => $log['call_reference_id'] ?? $log['id'] ?? null,
                'recording_source' => $log['recording_source'] ?? 'phone',
                'has_recording_media' => $log['has_recording_media'] ?? false,
                'from' => $log['from'] ?? '—',
                'to' => $log['to'] ?? '—',
            ])
            ->sortByDesc('at')
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $users
     * @param  array<int, array<string, mixed>>  $callLogs
     * @return array<int, array<string, mixed>>
     */
    protected function mergeContacts(array $users, array $callLogs, array $filters = []): array
    {
        $contacts = [];

        foreach ($users as $user) {
            $key = 'user:'.($user['id'] ?? Str::uuid()->toString());
            $contacts[$key] = [
                'contact_key' => $key,
                'name' => trim(($user['first_name'] ?? '').' '.($user['last_name'] ?? '')) ?: ($user['email'] ?? 'Zoom user'),
                'phone' => null,
                'email' => $user['email'] ?? null,
                'tag' => $user['type'] ?? 'user',
                'last_activity_at' => $user['last_login_time'] ?? null,
                'last_activity_type' => 'login',
            ];
        }

        foreach ($callLogs as $log) {
            foreach (['from_phone' => $log['from'] ?? '—', 'to_phone' => $log['to'] ?? '—'] as $phoneKey => $label) {
                $phone = $log[$phoneKey] ?? null;
                if (! $phone) {
                    continue;
                }

                $key = 'phone:'.$phone;
                $activityAt = $log['start_time'] ?? null;

                if (! isset($contacts[$key])) {
                    $contacts[$key] = [
                        'contact_key' => $key,
                        'name' => $label !== '—' ? explode(',', $label)[0] : $phone,
                        'phone' => $phone,
                        'email' => null,
                        'tag' => 'call_party',
                        'last_activity_at' => $activityAt,
                        'last_activity_type' => 'call',
                    ];

                    continue;
                }

                if ($activityAt && $this->isNewer($activityAt, $contacts[$key]['last_activity_at'])) {
                    $contacts[$key]['last_activity_at'] = $activityAt;
                    $contacts[$key]['last_activity_type'] = 'call';
                }
            }
        }

        $collection = collect($contacts)->values();

        if (($filters['filter'] ?? '') === 'with_phone') {
            $collection = $collection->filter(fn ($c) => filled($c['phone']));
        }

        if (($filters['filter'] ?? '') === 'recent') {
            $collection = $collection->filter(fn ($c) => filled($c['last_activity_at']));
        }

        if ($search = $filters['search'] ?? null) {
            $needle = strtolower($search);
            $collection = $collection->filter(function ($c) use ($needle) {
                return str_contains(strtolower($c['name'] ?? ''), $needle)
                    || str_contains(strtolower($c['email'] ?? ''), $needle)
                    || str_contains(strtolower($c['phone'] ?? ''), $needle);
            });
        }

        return $collection
            ->sortByDesc('last_activity_at')
            ->values()
            ->all();
    }

    /**
     * @return array{type: string, needle: string}
     */
    protected function parseContactKey(string $contactKey): array
    {
        if (str_starts_with($contactKey, 'user:')) {
            return ['type' => 'user', 'needle' => substr($contactKey, 5)];
        }

        if (str_starts_with($contactKey, 'phone:')) {
            return ['type' => 'phone', 'needle' => substr($contactKey, 6)];
        }

        return ['type' => 'phone', 'needle' => $contactKey];
    }

    protected function isNewer(?string $candidate, ?string $current): bool
    {
        if (! $candidate) {
            return false;
        }

        if (! $current) {
            return true;
        }

        try {
            return Carbon::parse($candidate)->gt(Carbon::parse($current));
        } catch (\Throwable) {
            return false;
        }
    }

    protected function formatDuration(int $seconds): string
    {
        if ($seconds <= 0) {
            return '0s';
        }

        $minutes = intdiv($seconds, 60);
        $remaining = $seconds % 60;

        return $minutes > 0 ? "{$minutes}m {$remaining}s" : "{$remaining}s";
    }
}
