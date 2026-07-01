<?php

namespace App\Services\Integrations;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class ZoomApiService
{
    public function isMorpheus(): bool
    {
        return true;
    }

    public function isConfigured(): bool
    {
        return filled(config('integrations.morpheus.api_key'))
            && filled(config('integrations.morpheus.host'));
    }

    /**
     * @return array{connected: bool, message: string, expires_at: string|null}
     */
    public function connectionStatus(): array
    {
        try {
            $host = rtrim(config('integrations.morpheus.host'), '/');
            $apiKey = config('integrations.morpheus.api_key');
            
            $response = Http::withHeaders(['X-API-Key' => $apiKey])
                ->acceptJson()
                ->timeout(12)
                ->get("https://{$host}/api/v1/call-control/users", ['limit' => 1]);

            if ($response->successful()) {
                return [
                    'connected' => true,
                    'message' => 'Connected to Morpheus CX API.',
                    'expires_at' => null,
                ];
            }
            return [
                'connected' => false,
                'message' => 'Morpheus API error: ' . ($response->json('message') ?? $response->body()),
                'expires_at' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'connected' => false,
                'message' => 'Connection failed: ' . $e->getMessage(),
                'expires_at' => null,
            ];
        }
    }

    /**
     * @return array{phone_available: bool, messages: array<int, string>}
     */
    public function connectionDiagnostics(): array
    {
        return [
            'phone_available' => true,
            'messages' => [],
        ];
    }

    /**
     * @return array{users: array<int, array<string, mixed>>, next_page_token: string|null}
     */
    public function listUsers(array $filters = []): array
    {
        try {
            $host = rtrim(config('integrations.morpheus.host'), '/');
            $apiKey = config('integrations.morpheus.api_key');
            $response = Http::withHeaders(['X-API-Key' => $apiKey])
                ->acceptJson()
                ->timeout(12)
                ->get("https://{$host}/api/v1/call-control/users", [
                    'limit' => min((int) ($filters['per_page'] ?? 50), 100),
                    'search' => $filters['search'] ?? null,
                ]);

            if ($response->successful()) {
                $users = [];
                foreach ($response->json('users') ?? [] as $row) {
                    $users[] = [
                        'id' => $row['id'],
                        'first_name' => $row['first_name'] ?? $row['username'],
                        'last_name' => $row['last_name'] ?? '',
                        'email' => $row['email'] ?? '',
                        'type' => 'user',
                        'status' => $row['status'] ?? 'active',
                    ];
                }
                return [
                    'users' => $users,
                    'next_page_token' => null,
                ];
            }
        } catch (\Throwable $e) {}
        return ['users' => [], 'next_page_token' => null];
    }

    /**
     * @return array{users: array<int, array<string, mixed>>, next_page_token: string|null, warning: string|null}
     */
    public function listPhoneUsers(array $filters = []): array
    {
        try {
            $host = rtrim(config('integrations.morpheus.host'), '/');
            $apiKey = config('integrations.morpheus.api_key');
            $response = Http::withHeaders(['X-API-Key' => $apiKey])
                ->acceptJson()
                ->timeout(12)
                ->get("https://{$host}/api/v1/call-control/extensions");

            if ($response->successful()) {
                $users = [];
                foreach ($response->json('extensions') ?? [] as $row) {
                    $users[] = [
                        'id' => $row['id'] ?? $row['extension'],
                        'name' => $row['name'] ?? ('Extension ' . $row['extension']),
                        'email' => $row['email'] ?? '',
                        'extension_number' => $row['extension'],
                        'phone_numbers' => [$row['extension']],
                        'default_caller_id' => $row['extension'],
                        'status' => 'active',
                    ];
                }
                return [
                    'users' => $users,
                    'next_page_token' => null,
                    'warning' => null,
                ];
            }
            return [
                'users' => [],
                'next_page_token' => null,
                'warning' => 'Morpheus API error: ' . $response->body(),
            ];
        } catch (\Throwable $e) {
            return [
                'users' => [],
                'next_page_token' => null,
                'warning' => 'Connection failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * @return array{logs: array<int, array<string, mixed>>, next_page_token: string|null}
     */
    public function listCallLogs(array $filters = []): array
    {
        try {
            $host = rtrim(config('integrations.morpheus.host'), '/');
            $apiKey = config('integrations.morpheus.api_key');
            $response = Http::withHeaders(['X-API-Key' => $apiKey])
                ->acceptJson()
                ->timeout(12)
                ->get("https://{$host}/api/v1/call-control/calls");

            if ($response->successful()) {
                $logs = [];
                foreach ($response->json('calls') ?? [] as $row) {
                    $logs[] = [
                        'id' => $row['uuid'],
                        'direction' => $row['direction'] ?? 'inbound',
                        'from' => $row['caller_name'] ?? $row['caller_id'],
                        'to' => $row['callee_name'] ?? $row['callee_id'],
                        'from_phone' => $row['caller_id'],
                        'to_phone' => $row['callee_id'],
                        'start_time' => $row['created_at'],
                        'result' => $row['status'] === 'active' ? 'Active Call' : 'connected',
                        'duration' => $row['duration'] ?? 0,
                        'recording' => '—',
                    ];
                }
                return [
                    'logs' => $logs,
                    'next_page_token' => null,
                ];
            }
        } catch (\Throwable $e) {}
        return ['logs' => [], 'next_page_token' => null];
    }

    public function transferCall(string $uuid, string $destination): array
    {
        $host = rtrim(config('integrations.morpheus.host'), '/');
        $apiKey = config('integrations.morpheus.api_key');

        $response = Http::withHeaders(['X-API-Key' => $apiKey])
            ->acceptJson()
            ->post("https://{$host}/api/v1/call-control/calls/{$uuid}/transfer", [
                'destination' => $destination,
            ]);

        if ($response->successful()) {
            return [
                'success' => true,
                'message' => 'Call transfer initiated successfully.',
            ];
        }

        return [
            'success' => false,
            'message' => $response->json('message') ?? 'Morpheus API returned status code ' . $response->status(),
        ];
    }

    public function accountId(): ?string
    {
        return config('integrations.morpheus.host');
    }

    public function clientId(): ?string
    {
        return config('integrations.morpheus.api_key');
    }

    public function maskedSecret(): string
    {
        $key = config('integrations.morpheus.api_key');
        if (strlen($key) <= 8) {
            return '••••••••';
        }
        return '••••••••' . substr($key, -4);
    }

    public function webhookSecret(): ?string
    {
        return null;
    }

    public function requiredScopes(): array
    {
        return [];
    }

    // Stub methods returning empty arrays to support remaining hub views without errors
    public function listRecordings(array $filters = []): array
    {
        return ['recordings' => [], 'next_page_token' => null];
    }

    public function listVoiceMails(array $filters = []): array
    {
        return ['voice_mails' => [], 'next_page_token' => null];
    }

    public function listSmsSessions(array $filters = []): array
    {
        return ['sessions' => [], 'next_page_token' => null];
    }

    public function listCallQueues(array $filters = []): array
    {
        return ['queues' => [], 'next_page_token' => null];
    }

    public function listTeamChatChannels(array $filters = []): array
    {
        return ['channels' => [], 'next_page_token' => null];
    }

    public function sendTeamChatMessage(string $userId, array $payload): array
    {
        return ['success' => false];
    }

    public function clearAccessTokenCache(): void
    {
        // No OAuth tokens to clear
    }

    public function humanizeError(string $msg): string
    {
        return $msg;
    }

    /**
     * Stream a recording. Morpheus CX does not expose a recording stream endpoint
     * in the current API; throw a descriptive exception so the controller returns 404.
     */
    public function streamRecording(string $source, string $recordingId, bool $download = false, ?string $callReferenceId = null): never
    {
        throw new \RuntimeException('Recording not found.');
    }

    /**
     * Stream a voicemail. Morpheus CX does not expose a voicemail stream endpoint
     * in the current API; throw a descriptive exception so the controller returns 404.
     */
    public function streamVoicemail(string $fileId, bool $download = false): never
    {
        throw new \RuntimeException('Voicemail not found.');
    }
}
