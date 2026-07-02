<?php

namespace App\Services\Integrations;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\PendingRequest;

/**
 * MorpheusCX Call-Control API client.
 *
 * Base URL: https://{host}/api/v1/call-control
 * Auth    : X-API-Key header (or Bearer token — we always use the header)
 *
 * Sections implemented (all endpoints from the OpenAPI spec):
 *   - Calls        : list, get, originate, click-to-call
 *   - CDR          : list (call history)
 *   - Recordings   : list, download
 *   - Voicemails   : list, download
 *   - Call Actions : hangup, hold, unhold, park, unpark, unbridge,
 *                    transfer, bridge, transfer-to-queue, transfer-to-agent,
 *                    join-conference, disposition
 *   - Queues       : list, get, create, update, delete, waiting
 *   - Conferences  : list, get, create, update, delete,
 *                    members, member-action, kick-all
 *   - Leads        : list, get, create, update, delete
 *   - Campaigns    : list, get, create, update, delete
 *   - Lists        : list, get, create, update, delete
 *   - Users        : list, get, create, update, delete
 *   - Extensions   : list, get, create, update, delete
 */
class ZoomApiService
{
    // -------------------------------------------------------------------------
    // Identity / health
    // -------------------------------------------------------------------------

    public function isMorpheus(): bool
    {
        return true;
    }

    public function isConfigured(): bool
    {
        return filled(config('integrations.morpheus.api_key'))
            && filled(config('integrations.morpheus.host'));
    }

    /** @return array{connected: bool, message: string, expires_at: null} */
    public function connectionStatus(): array
    {
        try {
            $response = $this->client()->get($this->url('/users'), ['limit' => 1]);

            if ($response->successful()) {
                return ['connected' => true,  'message' => 'Connected to Morpheus CX API.', 'expires_at' => null];
            }
            return ['connected' => false, 'message' => 'Morpheus API error: ' . ($response->json('error') ?? $response->body()), 'expires_at' => null];
        } catch (\Throwable $e) {
            return ['connected' => false, 'message' => 'Connection failed: ' . $e->getMessage(), 'expires_at' => null];
        }
    }

    /** @return array{phone_available: bool, messages: array<int, string>} */
    public function connectionDiagnostics(): array
    {
        $messages = [];

        foreach ([
            '/cdr' => 'cdr:read (call history)',
            '/recordings' => 'recordings:read',
            '/voicemails' => 'voicemails:read',
        ] as $path => $label) {
            try {
                $response = $this->client()->get($this->url($path), ['limit' => 1]);
                if ($response->status() === 403) {
                    $messages[] = "Missing permission: {$label}";
                }
            } catch (\Throwable $e) {
                $messages[] = "{$label}: ".$e->getMessage();
            }
        }

        return ['phone_available' => true, 'messages' => $messages];
    }

    // -------------------------------------------------------------------------
    // Compat helpers (used by controller / settings view)
    // -------------------------------------------------------------------------

    public function accountId(): ?string   { return config('integrations.morpheus.host'); }
    public function clientId(): ?string    { return config('integrations.morpheus.api_key'); }
    public function webhookSecret(): ?string { return null; }
    public function requiredScopes(): array
    {
        return [
            'calls:read',
            'calls:control',
            'calls:originate',
            'cdr:read',
            'recordings:read',
            'voicemails:read',
            'queues:read',
            'conferences:read',
            'leads:read',
            'campaigns:read',
            'lists:read',
            'users:read',
            'extensions:read',
        ];
    }
    public function clearAccessTokenCache(): void {}
    public function humanizeError(string $msg): string { return $msg; }

    public function maskedSecret(): string
    {
        $key = (string) config('integrations.morpheus.api_key');
        return strlen($key) <= 8 ? '••••••••' : '••••••••' . substr($key, -4);
    }

    // =========================================================================
    // CALLS  (requires calls:read)
    // =========================================================================

    /**
     * GET /calls — List active calls.
     * @return array{calls: array<int, array<string, mixed>>}
     */
    public function listCalls(): array
    {
        try {
            $response = $this->client()->get($this->url('/calls'));
            if ($response->successful()) {
                return ['calls' => $response->json('calls') ?? []];
            }
        } catch (\Throwable) {}
        return ['calls' => []];
    }

    /**
     * GET /calls/{uuid} — Get a single live call.
     * @return array<string, mixed>|null
     */
    public function getCall(string $uuid): ?array
    {
        try {
            $response = $this->client()->get($this->url("/calls/{$uuid}"));
            if ($response->successful()) {
                return $response->json();
            }
        } catch (\Throwable) {}
        return null;
    }

    /**
     * Click-to-call: rings extension first, then connects destination.
     *
     * @return array{ok: bool, call_uuid?: string, error?: string, action?: string}
     */
    public function clickToCall(string $extension, string $destination, array $options = []): array
    {
        return $this->postOriginate('/click-to-call', array_merge([
            'extension' => trim($extension),
            'destination' => trim($destination),
        ], array_filter([
            'caller_id_number' => $options['caller_id_number'] ?? null,
            'caller_id_name' => $options['caller_id_name'] ?? null,
            'timeout_sec' => $options['timeout_sec'] ?? null,
        ])));
    }

    /**
     * Originate via click-to-call (extension) or /calls/originate (from/to).
     *
     * @return array{ok: bool, call_uuid?: string, error?: string, action?: string, attempted?: array<int, string>}
     */
    public function originateCall(string $fromExtension, string $destination, array $options = []): array
    {
        $fromExtension = trim($fromExtension);
        $destination = trim($destination);

        if ($fromExtension === '' || $destination === '') {
            return ['ok' => false, 'error' => 'Extension and destination are required.'];
        }

        $attempted = [];
        $extra = array_filter([
            'caller_id_number' => $options['caller_id_number'] ?? null,
            'caller_id_name' => $options['caller_id_name'] ?? null,
            'timeout_sec' => $options['timeout_sec'] ?? null,
        ]);

        $click = $this->postOriginate('/click-to-call', array_merge([
            'extension' => $fromExtension,
            'destination' => $destination,
        ], $extra));
        $attempted[] = 'POST /click-to-call';
        if ($click['ok'] ?? false) {
            return array_merge($click, ['attempted' => $attempted]);
        }

        $originate = $this->postOriginate('/calls/originate', array_merge([
            'from' => $fromExtension,
            'to' => $destination,
        ], $extra));
        $attempted[] = 'POST /calls/originate';

        if ($originate['ok'] ?? false) {
            return array_merge($originate, ['attempted' => $attempted]);
        }

        return [
            'ok' => false,
            'error' => $originate['error'] ?? $click['error'] ?? 'Could not originate call.',
            'attempted' => $attempted,
        ];
    }

    /**
     * @return array{ok: bool, call_uuid?: string, error?: string, action?: string}
     */
    protected function postOriginate(string $path, array $body): array
    {
        try {
            $response = $this->client()->post($this->url($path), $body);

            if ($response->successful()) {
                $json = $response->json() ?? [];

                return array_merge([
                    'ok' => (bool) ($json['ok'] ?? true),
                    'action' => 'originate',
                ], $json);
            }

            if ($response->status() === 403) {
                return ['ok' => false, 'error' => 'API key lacks calls:originate permission.'];
            }

            return [
                'ok' => false,
                'error' => (string) ($response->json('error') ?? 'Originate failed (HTTP '.$response->status().').'),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // =========================================================================
    // CALL ACTIONS  (requires calls:control)
    // =========================================================================

    /**
     * POST /calls/{uuid}/hangup — Hang up a call.
     * @return array{ok: bool, action: string}
     */
    public function hangup(string $uuid): array
    {
        return $this->callAction($uuid, 'hangup');
    }

    /**
     * POST /calls/{uuid}/hold — Put a call on hold (MOH).
     */
    public function hold(string $uuid): array
    {
        return $this->callAction($uuid, 'hold');
    }

    /**
     * POST /calls/{uuid}/unhold — Remove a call from hold.
     */
    public function unhold(string $uuid): array
    {
        return $this->callAction($uuid, 'unhold');
    }

    /**
     * POST /calls/{uuid}/park — Park a call onto MOH.
     */
    public function park(string $uuid): array
    {
        return $this->callAction($uuid, 'park');
    }

    /**
     * POST /calls/{uuid}/unpark — Send a parked call to an extension/number.
     */
    public function unpark(string $uuid, string $destination): array
    {
        return $this->callAction($uuid, 'unpark', ['destination' => $destination]);
    }

    /**
     * POST /calls/{uuid}/unbridge — Split a call out of its bridge onto MOH.
     */
    public function unbridge(string $uuid): array
    {
        return $this->callAction($uuid, 'unbridge');
    }

    /**
     * POST /calls/{uuid}/transfer — Blind-transfer to extension or number.
     */
    public function transferCall(string $uuid, string $destination): array
    {
        return $this->callAction($uuid, 'transfer', ['destination' => $destination]);
    }

    /**
     * POST /calls/{uuid}/bridge — Bridge this call to another active call.
     */
    public function bridge(string $uuid, string $otherUuid): array
    {
        return $this->callAction($uuid, 'bridge', ['other_uuid' => $otherUuid]);
    }

    /**
     * POST /calls/{uuid}/transfer-to-queue — Put the call into a queue.
     */
    public function transferToQueue(string $uuid, string $queueId): array
    {
        return $this->callAction($uuid, 'transfer-to-queue', ['queue_id' => $queueId]);
    }

    /**
     * POST /calls/{uuid}/transfer-to-agent — Hand the call to a specific agent.
     */
    public function transferToAgent(string $uuid, string $agentUserId): array
    {
        return $this->callAction($uuid, 'transfer-to-agent', ['agent_user_id' => $agentUserId]);
    }

    /**
     * POST /calls/{uuid}/join-conference — Drop the call into a conference room.
     */
    public function joinConference(string $uuid, string $conference): array
    {
        return $this->callAction($uuid, 'join-conference', ['conference' => $conference]);
    }

    /**
     * POST /calls/{uuid}/disposition — Record a call outcome / disposition.
     *
     * @param  array{disposition: string, note?: string, update_lead?: bool} $data
     */
    public function dispositionCall(string $uuid, array $data): array
    {
        try {
            $response = $this->client()
                ->post($this->url("/calls/{$uuid}/disposition"), $data);
            return $response->json() ?? ['ok' => false];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // =========================================================================
    // QUEUES  (requires queues:read|create|update|delete)
    // =========================================================================

    /**
     * GET /queues — List queues (includes live waiting/longest_wait_sec state).
     * @return array{queues: array<int, array<string, mixed>>}
     */
    public function listQueues(): array
    {
        try {
            $response = $this->client()->get($this->url('/queues'));
            if ($response->successful()) {
                return ['queues' => $response->json('queues') ?? []];
            }
        } catch (\Throwable) {}
        return ['queues' => []];
    }

    /**
     * GET /queues/{id} — Get a single queue.
     */
    public function getQueue(string $id): ?array
    {
        try {
            $response = $this->client()->get($this->url("/queues/{$id}"));
            return $response->successful() ? $response->json() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * POST /queues — Create a queue.
     *
     * @param array{name: string, description?: string, strategy?: string, moh_sound?: string, max_wait_time?: int, wrap_up_time?: int, status?: string} $data
     */
    public function createQueue(array $data): array
    {
        try {
            $response = $this->client()->post($this->url('/queues'), $data);
            return $response->json() ?? ['error' => 'Unknown error'];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * PATCH /queues/{id} — Update a queue.
     */
    public function updateQueue(string $id, array $data): array
    {
        try {
            $response = $this->client()->patch($this->url("/queues/{$id}"), $data);
            return $response->json() ?? ['error' => 'Unknown error'];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * DELETE /queues/{id} — Delete a queue.
     */
    public function deleteQueue(string $id): array
    {
        try {
            $response = $this->client()->delete($this->url("/queues/{$id}"));
            return $response->json() ?? ['ok' => false];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * GET /queues/{id}/waiting — List callers currently waiting in a queue.
     */
    public function getQueueWaiting(string $id): array
    {
        try {
            $response = $this->client()->get($this->url("/queues/{$id}/waiting"));
            if ($response->successful()) {
                return ['waiting' => $response->json('waiting') ?? []];
            }
        } catch (\Throwable) {}
        return ['waiting' => []];
    }

    // =========================================================================
    // CONFERENCES  (requires conferences:read|create|update|delete|control)
    // =========================================================================

    /**
     * GET /conferences — List conference rooms.
     * @return array{conferences: array<int, array<string, mixed>>}
     */
    public function listConferences(): array
    {
        try {
            $response = $this->client()->get($this->url('/conferences'));
            if ($response->successful()) {
                return ['conferences' => $response->json('conferences') ?? []];
            }
        } catch (\Throwable) {}
        return ['conferences' => []];
    }

    /**
     * GET /conferences/{id} — Get a single conference room.
     */
    public function getConference(string $id): ?array
    {
        try {
            $response = $this->client()->get($this->url("/conferences/{$id}"));
            return $response->successful() ? $response->json() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * POST /conferences — Create a conference room.
     *
     * @param array{name: string, extension_num?: string, pin?: string, admin_pin?: string, max_members?: int, record?: bool, moh_sound?: string, announce?: bool, enabled?: bool} $data
     */
    public function createConference(array $data): array
    {
        try {
            $response = $this->client()->post($this->url('/conferences'), $data);
            return $response->json() ?? ['error' => 'Unknown error'];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * PATCH /conferences/{id} — Update a conference room.
     */
    public function updateConference(string $id, array $data): array
    {
        try {
            $response = $this->client()->patch($this->url("/conferences/{$id}"), $data);
            return $response->json() ?? ['error' => 'Unknown error'];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * DELETE /conferences/{id} — Delete a conference room.
     */
    public function deleteConference(string $id): array
    {
        try {
            $response = $this->client()->delete($this->url("/conferences/{$id}"));
            return $response->json() ?? ['ok' => false];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * GET /conferences/{id}/members — Get live roster of a conference room.
     */
    public function getConferenceMembers(string $id): array
    {
        try {
            $response = $this->client()->get($this->url("/conferences/{$id}/members"));
            if ($response->successful()) {
                return $response->json() ?? ['members' => []];
            }
        } catch (\Throwable) {}
        return ['members' => []];
    }

    /**
     * POST /conferences/{id}/members/{member}/{action} — Act on a conference member.
     *
     * @param string $action  mute|unmute|deaf|undeaf|kick
     */
    public function conferenceMemberAction(string $id, string $member, string $action): array
    {
        try {
            $response = $this->client()
                ->post($this->url("/conferences/{$id}/members/{$member}/{$action}"));
            return $response->json() ?? ['ok' => false];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * POST /conferences/{id}/kick-all — Remove all members from a conference room.
     */
    public function kickAllConferenceMembers(string $id): array
    {
        try {
            $response = $this->client()->post($this->url("/conferences/{$id}/kick-all"));
            return $response->json() ?? ['ok' => false];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // =========================================================================
    // LEADS  (requires leads:read|create|update|delete)
    // =========================================================================

    /**
     * GET /leads — List leads.
     *
     * @param array{limit?: int, offset?: int, list_id?: string, search?: string} $filters
     * @return array{leads: array<int, array<string, mixed>>}
     */
    public function listLeads(array $filters = []): array
    {
        try {
            $params = array_filter([
                'limit'   => $filters['limit']   ?? 100,
                'offset'  => $filters['offset']  ?? 0,
                'list_id' => $filters['list_id'] ?? null,
                'search'  => $filters['search']  ?? null,
            ], fn($v) => !is_null($v));

            $response = $this->client()->get($this->url('/leads'), $params);
            if ($response->successful()) {
                return ['leads' => $response->json('leads') ?? []];
            }
        } catch (\Throwable) {}
        return ['leads' => []];
    }

    /**
     * GET /leads/{id} — Get a single lead.
     */
    public function getLead(string $id): ?array
    {
        try {
            $response = $this->client()->get($this->url("/leads/{$id}"));
            return $response->successful() ? $response->json() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * POST /leads — Create a lead.
     *
     * @param array{phone_number: string, list_id: string, first_name?: string, last_name?: string, email?: string, ...} $data
     */
    public function createLead(array $data): array
    {
        try {
            $response = $this->client()->post($this->url('/leads'), $data);
            return $response->json() ?? ['error' => 'Unknown error'];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * PATCH /leads/{id} — Update a lead.
     */
    public function updateLead(string $id, array $data): array
    {
        try {
            $response = $this->client()->patch($this->url("/leads/{$id}"), $data);
            return $response->json() ?? ['error' => 'Unknown error'];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * DELETE /leads/{id} — Delete a lead.
     */
    public function deleteLead(string $id): array
    {
        try {
            $response = $this->client()->delete($this->url("/leads/{$id}"));
            return $response->json() ?? ['ok' => false];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // =========================================================================
    // CAMPAIGNS  (requires campaigns:read|create|update|delete)
    // =========================================================================

    /**
     * GET /campaigns — List campaigns.
     *
     * @param array{limit?: int, offset?: int, status?: string} $filters
     * @return array{campaigns: array<int, array<string, mixed>>}
     */
    public function listCampaigns(array $filters = []): array
    {
        try {
            $params = array_filter([
                'limit'  => $filters['limit']  ?? 100,
                'offset' => $filters['offset'] ?? 0,
                'status' => $filters['status'] ?? null,
            ], fn($v) => !is_null($v));

            $response = $this->client()->get($this->url('/campaigns'), $params);
            if ($response->successful()) {
                return ['campaigns' => $response->json('campaigns') ?? []];
            }
        } catch (\Throwable) {}
        return ['campaigns' => []];
    }

    /**
     * GET /campaigns/{id} — Get a single campaign.
     */
    public function getCampaign(string $id): ?array
    {
        try {
            $response = $this->client()->get($this->url("/campaigns/{$id}"));
            return $response->successful() ? $response->json() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * POST /campaigns — Create a campaign.
     *
     * @param array{name: string, dial_mode?: string, status?: string, dial_ratio?: float, ...} $data
     */
    public function createCampaign(array $data): array
    {
        try {
            $response = $this->client()->post($this->url('/campaigns'), $data);
            return $response->json() ?? ['error' => 'Unknown error'];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * PATCH /campaigns/{id} — Update a campaign.
     */
    public function updateCampaign(string $id, array $data): array
    {
        try {
            $response = $this->client()->patch($this->url("/campaigns/{$id}"), $data);
            return $response->json() ?? ['error' => 'Unknown error'];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * DELETE /campaigns/{id} — Delete a campaign.
     */
    public function deleteCampaign(string $id): array
    {
        try {
            $response = $this->client()->delete($this->url("/campaigns/{$id}"));
            return $response->json() ?? ['ok' => false];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // =========================================================================
    // LISTS  (requires lists:read|create|update|delete)
    // =========================================================================

    /**
     * GET /lists — List lead lists.
     *
     * @param array{limit?: int, offset?: int, campaign_id?: string} $filters
     * @return array{lists: array<int, array<string, mixed>>}
     */
    public function listLeadLists(array $filters = []): array
    {
        try {
            $params = array_filter([
                'limit'       => $filters['limit']       ?? 100,
                'offset'      => $filters['offset']      ?? 0,
                'campaign_id' => $filters['campaign_id'] ?? null,
            ], fn($v) => !is_null($v));

            $response = $this->client()->get($this->url('/lists'), $params);
            if ($response->successful()) {
                return ['lists' => $response->json('lists') ?? []];
            }
        } catch (\Throwable) {}
        return ['lists' => []];
    }

    /**
     * GET /lists/{id} — Get a single list.
     */
    public function getLeadList(string $id): ?array
    {
        try {
            $response = $this->client()->get($this->url("/lists/{$id}"));
            return $response->successful() ? $response->json() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * POST /lists — Create a lead list.
     *
     * @param array{name: string, description?: string, status?: string, campaign_id?: string, ...} $data
     */
    public function createLeadList(array $data): array
    {
        try {
            $response = $this->client()->post($this->url('/lists'), $data);
            return $response->json() ?? ['error' => 'Unknown error'];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * PATCH /lists/{id} — Update a list.
     */
    public function updateLeadList(string $id, array $data): array
    {
        try {
            $response = $this->client()->patch($this->url("/lists/{$id}"), $data);
            return $response->json() ?? ['error' => 'Unknown error'];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * DELETE /lists/{id} — Delete a list.
     */
    public function deleteLeadList(string $id): array
    {
        try {
            $response = $this->client()->delete($this->url("/lists/{$id}"));
            return $response->json() ?? ['ok' => false];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // =========================================================================
    // USERS  (requires users:read|create|update|delete)
    // =========================================================================

    /**
     * GET /users — List tenant users.
     *
     * @param array{limit?: int, offset?: int, search?: string} $filters
     * @return array{users: array<int, array<string, mixed>>}
     */
    public function listUsers(array $filters = []): array
    {
        try {
            $params = array_filter([
                'limit'  => $filters['limit']  ?? 100,
                'offset' => $filters['offset'] ?? 0,
                'search' => $filters['search'] ?? null,
            ], fn($v) => !is_null($v));

            $response = $this->client()->get($this->url('/users'), $params);
            if ($response->successful()) {
                $users = [];
                foreach ($response->json('users') ?? [] as $row) {
                    $users[] = [
                        'id'         => $row['id'],
                        'first_name' => $row['first_name'] ?? ($row['username'] ?? ''),
                        'last_name'  => $row['last_name']  ?? '',
                        'email'      => $row['email']      ?? '',
                        'type'       => 'user',
                        'status'     => $row['status']     ?? 'active',
                    ];
                }
                return ['users' => $users, 'next_page_token' => null];
            }
        } catch (\Throwable) {}
        return ['users' => [], 'next_page_token' => null];
    }

    /**
     * GET /users/{id} — Get a single user.
     */
    public function getUser(string $id): ?array
    {
        try {
            $response = $this->client()->get($this->url("/users/{$id}"));
            return $response->successful() ? $response->json() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * POST /users — Create a user.
     *
     * @param array{username: string, password: string, first_name?: string, last_name?: string, email?: string, role?: string, status?: string} $data
     */
    public function createUser(array $data): array
    {
        try {
            $response = $this->client()->post($this->url('/users'), $data);
            return $response->json() ?? ['error' => 'Unknown error'];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * PATCH /users/{id} — Update a user.
     */
    public function updateUser(string $id, array $data): array
    {
        try {
            $response = $this->client()->patch($this->url("/users/{$id}"), $data);
            return $response->json() ?? ['error' => 'Unknown error'];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * DELETE /users/{id} — Delete a user.
     */
    public function deleteUser(string $id): array
    {
        try {
            $response = $this->client()->delete($this->url("/users/{$id}"));
            return $response->json() ?? ['ok' => false];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // =========================================================================
    // EXTENSIONS  (requires extensions:read|create|update|delete)
    // =========================================================================

    /**
     * GET /extensions — List SIP extensions (raw API rows).
     *
     * @param array{limit?: int, offset?: int, user_id?: string} $filters
     * @return array{extensions: array<int, array<string, mixed>>}
     */
    public function listExtensions(array $filters = []): array
    {
        try {
            $params = array_filter([
                'limit' => $filters['limit'] ?? 100,
                'offset' => $filters['offset'] ?? 0,
                'user_id' => $filters['user_id'] ?? null,
            ], fn ($v) => ! is_null($v));

            $response = $this->client()->get($this->url('/extensions'), $params);
            if ($response->successful()) {
                return ['extensions' => $response->json('extensions') ?? []];
            }
        } catch (\Throwable) {
        }

        return ['extensions' => []];
    }

    /**
     * GET /extensions — List SIP extensions.
     *
     * @param array{limit?: int, offset?: int, user_id?: string} $filters
     * @return array{users: array<int, array<string, mixed>>, next_page_token: null, warning: null}
     */
    public function listPhoneUsers(array $filters = []): array
    {
        try {
            $params = array_filter([
                'limit'   => $filters['limit']   ?? 100,
                'offset'  => $filters['offset']  ?? 0,
                'user_id' => $filters['user_id'] ?? null,
            ], fn($v) => !is_null($v));

            $response = $this->client()->get($this->url('/extensions'), $params);
            if ($response->successful()) {
                $users = [];
                foreach ($response->json('extensions') ?? [] as $row) {
                    $users[] = [
                        'id'               => $row['id'] ?? $row['extension_num'],
                        'name'             => $row['caller_id_name'] ?? ('Extension ' . $row['extension_num']),
                        'email'            => $row['vm_email'] ?? '',
                        'extension_number' => $row['extension_num'],
                        'phone_numbers'    => [$row['extension_num']],
                        'default_caller_id'=> $row['caller_id_num'] ?? $row['extension_num'],
                        'status'           => $row['status'] ?? 'active',
                    ];
                }
                return ['users' => $users, 'next_page_token' => null, 'warning' => null];
            }
            return ['users' => [], 'next_page_token' => null, 'warning' => 'Morpheus API error: ' . $response->body()];
        } catch (\Throwable $e) {
            return ['users' => [], 'next_page_token' => null, 'warning' => 'Connection failed: ' . $e->getMessage()];
        }
    }

    /**
     * GET /extensions/{id} — Get a single extension.
     */
    public function getExtension(string $id): ?array
    {
        try {
            $response = $this->client()->get($this->url("/extensions/{$id}"));
            return $response->successful() ? $response->json() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * POST /extensions — Create a SIP extension.
     *
     * @param array{extension_num: string, password: string, caller_id_name?: string, ...} $data
     */
    public function createExtension(array $data): array
    {
        try {
            $response = $this->client()->post($this->url('/extensions'), $data);
            return $response->json() ?? ['error' => 'Unknown error'];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * PATCH /extensions/{id} — Update an extension.
     */
    public function updateExtension(string $id, array $data): array
    {
        try {
            $response = $this->client()->patch($this->url("/extensions/{$id}"), $data);
            return $response->json() ?? ['error' => 'Unknown error'];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * DELETE /extensions/{id} — Delete an extension.
     */
    public function deleteExtension(string $id): array
    {
        try {
            $response = $this->client()->delete($this->url("/extensions/{$id}"));
            return $response->json() ?? ['ok' => false];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // =========================================================================
    // CALL LOG  (active calls + CDR history)
    // =========================================================================

    /**
     * @return array{logs: array<int, array<string, mixed>>, next_page_token: string|null, warning: string|null}
     */
    public function listCdr(array $filters = []): array
    {
        $limit = (int) ($filters['per_page'] ?? 50);
        $offset = is_numeric($filters['page_token'] ?? null) ? (int) $filters['page_token'] : 0;

        try {
            $response = $this->client()->get($this->url('/cdr'), array_filter([
                'from' => $this->cdrTimestamp($filters['from'] ?? null),
                'to' => $this->cdrTimestamp($filters['to'] ?? null, true),
                'direction' => $filters['direction'] ?? null,
                'search' => $filters['search'] ?? null,
                'limit' => $limit,
                'offset' => $offset,
            ], fn ($value) => $value !== null && $value !== ''));

            if ($response->status() === 403) {
                return [
                    'logs' => [],
                    'next_page_token' => null,
                    'warning' => 'API key lacks cdr:read permission.',
                ];
            }

            if (! $response->successful()) {
                return [
                    'logs' => [],
                    'next_page_token' => null,
                    'warning' => (string) ($response->json('error') ?? null),
                ];
            }

            $rows = $response->json('cdr') ?? [];
            $logs = collect(is_array($rows) ? $rows : [])
                ->map(fn (array $row) => $this->normalizeCdrRow($row))
                ->values()
                ->all();

            $nextPageToken = count($logs) >= $limit ? (string) ($offset + $limit) : null;

            return ['logs' => $logs, 'next_page_token' => $nextPageToken, 'warning' => null];
        } catch (\Throwable $e) {
            return ['logs' => [], 'next_page_token' => null, 'warning' => $e->getMessage()];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function listActiveCallLogs(): array
    {
        try {
            $response = $this->client()->get($this->url('/calls'));
            if (! $response->successful()) {
                return [];
            }

            $logs = [];
            foreach ($response->json('calls') ?? [] as $row) {
                $logs[] = [
                    'id' => $row['uuid'],
                    'direction' => $row['direction'] ?? 'inbound',
                    'from' => $row['caller_name'] ?? $row['phone_number'] ?? '—',
                    'to' => $row['callee_name'] ?? '—',
                    'from_phone' => $row['phone_number'] ?? '',
                    'to_phone' => $row['phone_number'] ?? '',
                    'start_time' => $row['started_at'] ?? null,
                    'result' => ($row['status'] ?? '') === 'active' ? 'Active Call' : ($row['status'] ?? '—'),
                    'duration' => (int) ($row['duration'] ?? 0),
                    'recording' => '—',
                    'campaign_id' => $row['campaign_id'] ?? null,
                    'source' => 'live',
                    'raw' => $row,
                ];
            }

            return $logs;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Active calls plus CDR history for the hub call-log view.
     *
     * @return array{logs: array<int, array<string, mixed>>, call_logs: array<int, array<string, mixed>>, next_page_token: string|null, warning: string|null}
     */
    public function listCallLogs(array $filters = []): array
    {
        $warning = null;
        $active = $this->listActiveCallLogs();
        $cdr = $this->listCdr($filters);

        if (filled($cdr['warning'] ?? null)) {
            $warning = $cdr['warning'];
        }

        $activeIds = collect($active)->pluck('id')->filter()->all();
        $history = collect($cdr['logs'])
            ->reject(fn (array $row) => in_array($row['id'], $activeIds, true))
            ->values()
            ->all();

        $logs = collect($active)
            ->concat($history)
            ->sortByDesc(fn (array $row) => $row['start_time'] ?? '')
            ->values()
            ->all();

        return [
            'logs' => $logs,
            'call_logs' => $logs,
            'next_page_token' => $cdr['next_page_token'],
            'warning' => $warning,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function normalizeCdrRow(array $row): array
    {
        $callUuid = (string) ($row['call_uuid'] ?? $row['id'] ?? '');
        $hasRecording = (bool) ($row['has_recording'] ?? false);

        return [
            'id' => $callUuid !== '' ? $callUuid : (string) ($row['id'] ?? ''),
            'direction' => $row['direction'] ?? 'inbound',
            'from' => $row['caller_id_name'] ?? $row['caller_id_number'] ?? '—',
            'to' => $row['destination_number'] ?? '—',
            'from_phone' => $row['caller_id_number'] ?? '',
            'to_phone' => $row['destination_number'] ?? '',
            'start_time' => $row['start_time'] ?? null,
            'result' => $row['call_outcome'] ?? $row['disposition_code'] ?? $row['hangup_cause'] ?? '—',
            'duration' => (int) ($row['billsec'] ?? $row['duration_sec'] ?? 0),
            'recording' => $hasRecording ? 'Yes' : '—',
            'has_recording_media' => $hasRecording,
            'recording_id' => $hasRecording ? $callUuid : null,
            'call_reference_id' => $callUuid,
            'campaign_id' => $row['campaign_id'] ?? null,
            'source' => 'cdr',
            'raw' => $row,
        ];
    }

    protected function cdrTimestamp(?string $date, bool $endOfDay = false): ?string
    {
        if (! filled($date)) {
            return null;
        }

        try {
            $parsed = \Carbon\Carbon::parse($date);

            return ($endOfDay ? $parsed->endOfDay() : $parsed->startOfDay())->toIso8601String();
        } catch (\Throwable) {
            return $date;
        }
    }

    /**
     * Normalize a call row for the communications hub (idempotent for listCallLogs output).
     *
     * @return array<string, mixed>
     */
    public function normalizeCallLog(array $row): array
    {
        if (isset($row['from']) && isset($row['direction'])) {
            return array_merge($row, ['raw' => $row['raw'] ?? $row]);
        }

        return [
            'id' => (string) ($row['uuid'] ?? $row['id'] ?? ''),
            'direction' => $row['direction'] ?? 'inbound',
            'from' => $row['caller_name'] ?? $row['phone_number'] ?? '—',
            'to' => $row['callee_name'] ?? '—',
            'from_phone' => $row['phone_number'] ?? '',
            'to_phone' => $row['phone_number'] ?? '',
            'start_time' => $row['started_at'] ?? null,
            'result' => ($row['status'] ?? '') === 'active' ? 'Active Call' : ($row['status'] ?? '—'),
            'duration' => (int) ($row['duration'] ?? 0),
            'recording' => '—',
            'campaign_id' => $row['campaign_id'] ?? null,
            'raw' => $row,
        ];
    }

    public function compactZoomReferenceId(string $id): string
    {
        return preg_replace('/[^a-zA-Z0-9]/', '', $id) ?? $id;
    }

    /**
     * @return array{recordings: array<int, array<string, mixed>>, next_page_token: null}
     */
    public function listPhoneRecordings(array $filters = []): array
    {
        return $this->listRecordings($filters);
    }

    /**
     * @return array{messages: array<int, array<string, mixed>>, next_page_token: null}
     */
    public function getSmsSessionMessages(string $sessionId, array $filters = []): array
    {
        return app(MorpheusPlatformApiService::class)->getSmsSessionMessages($sessionId, $filters);
    }

    /**
     * @return array{success: bool, error?: string}
     */
    public function sendSmsMessage(array $payload): array
    {
        return ['success' => false, 'error' => 'Morpheus CX does not support SMS via the Call-Control API.'];
    }

    /**
     * @return array{messages: array<int, array<string, mixed>>, next_page_token: null}
     */
    public function getTeamChatMessages(string $ownerUserId, array $filters = []): array
    {
        return app(MorpheusPlatformApiService::class)->getTeamChatMessages($ownerUserId, $filters);
    }

    protected function platformApi(): MorpheusPlatformApiService
    {
        return app(MorpheusPlatformApiService::class);
    }

    // =========================================================================
    // Stub methods — features not available in the Morpheus CX Call-Control API
    // =========================================================================

    public function listRecordings(array $filters = []): array
    {
        $warnings = [];

        try {
            $response = $this->client()->get($this->url('/recordings'), array_filter([
                'from' => $this->cdrTimestamp($filters['from'] ?? null),
                'to' => $this->cdrTimestamp($filters['to'] ?? null, true),
                'direction' => $filters['direction'] ?? null,
                'search' => $filters['search'] ?? null,
                'call_uuid' => $filters['call_uuid'] ?? null,
                'limit' => $filters['per_page'] ?? 50,
                'offset' => is_numeric($filters['page_token'] ?? null) ? (int) $filters['page_token'] : 0,
            ], fn ($value) => $value !== null && $value !== ''));

            if ($response->status() === 403) {
                return [
                    'recordings' => [],
                    'next_page_token' => null,
                    'warnings' => ['API key lacks recordings:read permission.'],
                ];
            }

            if (! $response->successful()) {
                $platform = $this->platformApi()->listRecordings($filters);
                if (filled($platform['warning'] ?? null)) {
                    $warnings[] = $platform['warning'];
                }

                return [
                    'recordings' => $this->normalizeMorpheusRecordings($platform['recordings'] ?? []),
                    'next_page_token' => null,
                    'warnings' => $warnings,
                ];
            }

            $rows = $response->json('recordings') ?? [];

            return [
                'recordings' => $this->normalizeMorpheusRecordings(is_array($rows) ? $rows : []),
                'next_page_token' => null,
                'warnings' => $warnings,
            ];
        } catch (\Throwable $e) {
            return ['recordings' => [], 'next_page_token' => null, 'warnings' => [$e->getMessage()]];
        }
    }

    public function listVoiceMails(array $filters = []): array
    {
        $status = $filters['status'] ?? null;
        if ($status === 'unread') {
            $status = 'new';
        }

        try {
            $response = $this->client()->get($this->url('/voicemails'), array_filter([
                'extension_id' => $filters['extension_id'] ?? null,
                'status' => $status,
                'limit' => $filters['per_page'] ?? 50,
                'offset' => is_numeric($filters['page_token'] ?? null) ? (int) $filters['page_token'] : 0,
            ], fn ($value) => $value !== null && $value !== ''));

            if ($response->status() === 403) {
                return [
                    'voice_mails' => [],
                    'next_page_token' => null,
                    'warning' => 'API key lacks voicemails:read permission.',
                ];
            }

            if (! $response->successful()) {
                $platform = $this->platformApi()->listVoiceMails($filters);

                return [
                    'voice_mails' => $this->normalizeMorpheusVoiceMails($platform['voice_mails'] ?? []),
                    'next_page_token' => null,
                    'warning' => $platform['warning'] ?? null,
                ];
            }

            $rows = $response->json('voicemails') ?? [];

            return [
                'voice_mails' => $this->normalizeMorpheusVoiceMails(is_array($rows) ? $rows : []),
                'next_page_token' => null,
                'warning' => null,
            ];
        } catch (\Throwable $e) {
            return ['voice_mails' => [], 'next_page_token' => null, 'warning' => $e->getMessage()];
        }
    }

    public function listSmsSessions(array $filters = []): array
    {
        $result = $this->platformApi()->listSmsSessions($filters);

        return [
            'sessions' => $this->normalizePlatformSmsSessions($result['sessions'] ?? []),
            'next_page_token' => null,
            'warning' => $result['warning'] ?? null,
        ];
    }

    public function listCallQueues(array $filters = []): array
    {
        // Alias to listQueues() but returns hub-compatible key
        $result = $this->listQueues();
        return ['queues' => $result['queues'] ?? [], 'next_page_token' => null, 'warning' => null];
    }

    public function listTeamChatChannels(array $filters = []): array
    {
        return $this->platformApi()->listTeamChatChannels($filters);
    }

    public function sendTeamChatMessage(string $userId, array $payload): array
    {
        if (! $this->isConfigured()) {
            return ['success' => false, 'error' => 'Morpheus CX is not configured.'];
        }

        try {
            $channelId = $payload['channel_id'] ?? $payload['to_channel'] ?? null;
            $path = $channelId ? "/chat/{$channelId}/messages" : '/chat/messages';
            $response = Http::timeout((int) config('integrations.communications.http_timeout_seconds', 12))
                ->acceptJson()
                ->withHeaders([
                    'X-API-Key' => (string) (config('integrations.morpheus.platform_api_key')
                        ?: config('integrations.morpheus.api_key')),
                ])
                ->post(
                    'https://'.config('integrations.morpheus.host').'/api/v1'.$path,
                    ['message' => $payload['message'] ?? $payload['body'] ?? '']
                );

            if ($response->successful()) {
                return ['success' => true, 'message' => $response->json()];
            }

            return [
                'success' => false,
                'error' => (string) ($response->json('error') ?? 'Could not send chat message.'),
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeMorpheusRecordings(array $rows): array
    {
        return collect($rows)->map(function (array $row) {
            $id = (string) ($row['id'] ?? '');
            $caller = $row['caller_id_number'] ?? $row['caller_id_name'] ?? 'Caller';
            $dest = $row['destination_number'] ?? 'Callee';
            $agent = $row['agent'] ?? $row['extension'] ?? null;

            return [
                'id' => $id,
                'topic' => trim(($row['direction'] ?? 'call').' · '.$caller.' → '.$dest),
                'source' => 'phone',
                'file_type' => 'audio/wav',
                'start_time' => $row['created_at'] ?? $row['finalized_at'] ?? null,
                'duration' => (int) ($row['duration_sec'] ?? 0),
                'host' => $agent ?? $caller,
                'has_media' => $id !== '',
                'call_uuid' => $row['call_uuid'] ?? null,
                'call_history_uuid' => $row['call_uuid'] ?? null,
                'call_id' => $row['call_uuid'] ?? null,
                'download_url' => $row['download_url'] ?? null,
                'raw' => $row,
            ];
        })->values()->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeMorpheusVoiceMails(array $rows): array
    {
        return collect($rows)->map(function (array $row) {
            $id = (string) ($row['id'] ?? '');
            $status = $row['status'] ?? 'new';

            return [
                'id' => $id,
                'file_id' => $id,
                'caller' => $row['caller_id_name'] ?? $row['caller_id_number'] ?? 'Unknown',
                'caller_number' => $row['caller_id_number'] ?? '',
                'callee' => $row['extension'] ?? '—',
                'date_time' => $row['created_at'] ?? null,
                'duration' => (int) ($row['duration_sec'] ?? 0),
                'status' => $status === 'new' ? 'unread' : $status,
                'has_media' => $id !== '',
                'download_url' => $row['download_url'] ?? null,
                'raw' => $row,
            ];
        })->values()->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function normalizePlatformRecordings(array $rows): array
    {
        return $this->normalizeMorpheusRecordings($rows);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function normalizePlatformVoiceMails(array $rows): array
    {
        return $this->normalizeMorpheusVoiceMails($rows);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function normalizePlatformSmsSessions(array $rows): array
    {
        return collect($rows)->map(function (array $row) {
            $sessionId = (string) ($row['session_id'] ?? $row['id'] ?? '');

            return [
                'session_id' => $sessionId,
                'phone_number' => $row['phone_number'] ?? $row['to'] ?? $row['from'] ?? '',
                'contact_name' => $row['contact_name'] ?? $row['name'] ?? null,
                'last_message' => $row['last_message'] ?? $row['preview'] ?? '',
                'last_message_at' => $row['last_message_at'] ?? $row['updated_at'] ?? null,
                'unread_count' => (int) ($row['unread_count'] ?? 0),
                'raw' => $row,
            ];
        })->values()->all();
    }

    /**
     * Stream recording audio from the Morpheus platform API when available.
     */
    public function streamRecording(string $source, string $recordingId, bool $download = false, ?string $callReferenceId = null): \Symfony\Component\HttpFoundation\Response
    {
        $response = $this->authenticatedMediaGet($this->url("/recordings/{$recordingId}/download"));

        if (! $response->successful()) {
            throw new \RuntimeException('Recording not found.');
        }

        $contentType = $response->header('Content-Type') ?: 'audio/wav';
        $disposition = $download ? 'attachment' : 'inline';

        return response($response->body(), 200, [
            'Content-Type' => $contentType,
            'Content-Disposition' => "{$disposition}; filename=\"recording-{$recordingId}.wav\"",
        ]);
    }

    public function streamVoicemail(string $fileId, bool $download = false): \Symfony\Component\HttpFoundation\Response
    {
        $query = $download ? '' : '?mark_read=1';
        $path = "/voicemails/{$fileId}/download".$query;
        $response = $this->authenticatedMediaGet($this->url($path));

        if (! $response->successful()) {
            throw new \RuntimeException('Voicemail not found.');
        }

        $contentType = $response->header('Content-Type') ?: 'audio/wav';
        $disposition = $download ? 'attachment' : 'inline';

        return response($response->body(), 200, [
            'Content-Type' => $contentType,
            'Content-Disposition' => "{$disposition}; filename=\"voicemail-{$fileId}.wav\"",
        ]);
    }

    protected function authenticatedMediaGet(string $url): \Illuminate\Http\Client\Response
    {
        return Http::timeout((int) config('integrations.communications.http_timeout_seconds', 12))
            ->withHeaders(['X-API-Key' => (string) config('integrations.morpheus.api_key')])
            ->get($url);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function client(): PendingRequest
    {
        $apiKey = config('integrations.morpheus.api_key');
        return Http::withHeaders(['X-API-Key' => $apiKey])
            ->acceptJson()
            ->timeout((int) config('integrations.communications.http_timeout_seconds', 12));
    }

    private function url(string $path): string
    {
        $host = rtrim(config('integrations.morpheus.host'), '/');
        return "https://{$host}/api/v1/call-control" . $path;
    }

    /**
     * Generic call-action helper for all POST /calls/{uuid}/{action} endpoints.
     */
    private function callAction(string $uuid, string $action, array $body = []): array
    {
        try {
            $request = $this->client();
            $url = $this->url("/calls/{$uuid}/{$action}");
            $response = empty($body) ? $request->post($url) : $request->post($url, $body);

            if ($response->successful()) {
                return array_merge(['ok' => true, 'action' => $action], $response->json() ?? []);
            }
            return [
                'ok'     => false,
                'action' => $action,
                'error'  => $response->json('error') ?? 'HTTP ' . $response->status(),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'action' => $action, 'error' => $e->getMessage()];
        }
    }
}
