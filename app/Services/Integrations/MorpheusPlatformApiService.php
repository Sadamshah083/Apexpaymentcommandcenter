<?php

namespace App\Services\Integrations;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Secondary Morpheus platform API (/api/v1/*) for recordings, chat, etc.
 * These routes exist on the tenant host but use different auth than call-control.
 */
class MorpheusPlatformApiService
{
    public function isConfigured(): bool
    {
        return filled(config('integrations.morpheus.api_key'))
            && filled(config('integrations.morpheus.host'));
    }

    /**
     * @return array{recordings: array<int, array<string, mixed>>, next_page_token: null, warning: string|null}
     */
    public function listRecordings(array $filters = []): array
    {
        $result = $this->fetchList('/recordings', 'recordings', $filters);

        return [
            'recordings' => $result['items'],
            'next_page_token' => null,
            'warning' => $result['warning'],
        ];
    }

    /**
     * @return array{voice_mails: array<int, array<string, mixed>>, next_page_token: null, warning: string|null}
     */
    public function listVoiceMails(array $filters = []): array
    {
        $result = $this->fetchList('/voicemails', 'voicemails', $filters);
        if ($result['items'] === [] && ($result['warning'] ?? null)) {
            $alt = $this->fetchList('/voicemail', 'voicemail', $filters);
            if ($alt['items'] !== []) {
                $result = $alt;
            }
        }

        return [
            'voice_mails' => $result['items'],
            'next_page_token' => null,
            'warning' => $result['warning'],
        ];
    }

    /**
     * @return array{sessions: array<int, array<string, mixed>>, next_page_token: null, warning: string|null}
     */
    public function listSmsSessions(array $filters = []): array
    {
        $result = $this->fetchList('/sms/sessions', 'sessions', $filters);
        if ($result['items'] === []) {
            $result = $this->fetchList('/sms', 'sessions', $filters);
        }

        return [
            'sessions' => $result['items'],
            'next_page_token' => null,
            'warning' => $result['warning'] ?? 'SMS is not available via the Morpheus API for this tenant.',
        ];
    }

    /**
     * @return array{channels: array<int, array<string, mixed>>, next_page_token: null, warning: string|null}
     */
    public function listTeamChatChannels(array $filters = []): array
    {
        $result = $this->fetchList('/chat', 'channels', $filters);
        if ($result['items'] === []) {
            $result = $this->fetchList('/chats', 'chats', $filters);
        }

        return [
            'channels' => $this->normalizeChatChannels($result['items']),
            'next_page_token' => null,
            'warning' => $result['warning'],
        ];
    }

    /**
     * @return array{messages: array<int, array<string, mixed>>, next_page_token: null}
     */
    public function getTeamChatMessages(string $ownerUserId, array $filters = []): array
    {
        $channelId = $filters['to_channel'] ?? null;
        $path = $channelId ? "/chat/{$channelId}/messages" : '/chat/messages';

        $result = $this->fetchList($path, 'messages', $filters);

        return ['messages' => $result['items'], 'next_page_token' => null];
    }

    /**
     * @return array{messages: array<int, array<string, mixed>>, next_page_token: null}
     */
    public function getSmsSessionMessages(string $sessionId, array $filters = []): array
    {
        $result = $this->fetchList("/sms/sessions/{$sessionId}/messages", 'messages', $filters);
        if ($result['items'] === []) {
            $result = $this->fetchList("/sms/{$sessionId}/messages", 'messages', $filters);
        }

        return ['messages' => $result['items'], 'next_page_token' => null];
    }

    public function recordingMediaUrl(string $recordingId): ?string
    {
        $host = (string) config('integrations.morpheus.host');

        return $host !== '' ? "https://{$host}/api/v1/recordings/{$recordingId}/media" : null;
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, warning: string|null}
     */
    protected function fetchList(string $path, string $itemsKey, array $filters = []): array
    {
        if (! $this->isConfigured()) {
            return ['items' => [], 'warning' => 'Morpheus is not configured.'];
        }

        try {
            $response = $this->client()->get($this->url($path), array_filter([
                'limit' => $filters['per_page'] ?? 50,
                'offset' => $filters['offset'] ?? null,
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
            ], fn ($v) => ! is_null($v)));

            if ($response->status() === 401) {
                return [
                    'items' => [],
                    'warning' => 'Platform API returned 401. Ask Morpheus for recordings/chat API credentials (separate from call-control key).',
                ];
            }

            if ($response->status() === 404) {
                return ['items' => [], 'warning' => null];
            }

            if (! $response->successful()) {
                return [
                    'items' => [],
                    'warning' => (string) ($response->json('error') ?? 'Platform API error '.$response->status()),
                ];
            }

            $json = $response->json() ?? [];
            $items = $json[$itemsKey] ?? (is_array($json) && array_is_list($json) ? $json : []);

            return ['items' => is_array($items) ? $items : [], 'warning' => null];
        } catch (\Throwable $e) {
            return ['items' => [], 'warning' => $e->getMessage()];
        }
    }

  /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeChatChannels(array $rows): array
    {
        return collect($rows)->map(function (array $row) {
            return [
                'thread_key' => ($row['owner_user_id'] ?? 'platform').':'.($row['id'] ?? $row['channel_id'] ?? uniqid()),
                'channel_id' => $row['id'] ?? $row['channel_id'] ?? null,
                'owner_user_id' => $row['owner_user_id'] ?? 'platform',
                'owner_name' => $row['name'] ?? 'Team chat',
                'label' => $row['name'] ?? $row['label'] ?? 'Channel',
                'thread_type' => 'channel',
                'last_message_at' => $row['updated_at'] ?? $row['last_message_at'] ?? null,
            ];
        })->values()->all();
    }

    protected function client(): PendingRequest
    {
        $timeout = (int) config('integrations.communications.http_timeout_seconds', 12);

        return Http::timeout($timeout)
            ->acceptJson()
            ->withHeaders([
                'X-API-Key' => (string) config('integrations.morpheus.platform_api_key')
                    ?: (string) config('integrations.morpheus.api_key'),
            ]);
    }

    protected function url(string $path): string
    {
        $host = (string) config('integrations.morpheus.host');

        return 'https://'.$host.'/api/v1'.(str_starts_with($path, '/') ? $path : '/'.$path);
    }
}
