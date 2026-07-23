<?php

namespace App\Services\Communications;

use App\Models\LeadCampaign;
use App\Models\WorkflowLead;
use App\Models\Workspace;
use App\Support\LeadContactDisplay;
use App\Support\UsPhoneNormalizer;
use Illuminate\Database\Eloquent\Builder;

class DialerImportedLeadsService
{
    public function __construct(
        protected CommunicationsPhoneNotesService $phoneNotes,
    ) {}

    /**
     * @return array{leads: array<int, array<string, mixed>>, next_offset: int, has_more: bool, total: int}
     */
    public function paginate(Workspace $workspace, array $filters, int $offset, int $limit): array
    {
        $query = $this->baseQuery($workspace, $filters);
        $total = (clone $query)->count();
        $rows = $query
            ->offset($offset)
            ->limit($limit)
            ->get();

        $leads = $rows
            ->map(fn (WorkflowLead $lead) => $this->formatLead($lead))
            ->filter(fn (array $lead) => filled($lead['phone'] ?? null))
            ->values()
            ->all();

        $nextOffset = $offset + count($rows);

        return [
            'leads' => $leads,
            'next_offset' => $nextOffset,
            'has_more' => $nextOffset < $total,
            'total' => $total,
        ];
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    public function campaignOptions(Workspace $workspace): array
    {
        return LeadCampaign::query()
            ->where('workspace_id', $workspace->id)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (LeadCampaign $campaign) => [
                'id' => (int) $campaign->id,
                'name' => (string) $campaign->name,
            ])
            ->all();
    }

    /**
     * @return array<int, array{id: int, name: string, total_leads: int}>
     */
    public function fileOptions(Workspace $workspace, ?int $assignedUserId = null): array
    {
        // Agents: fast distinct-sheet lookup for assigned undialed leads (no whereHas scan).
        if ($assignedUserId !== null && $assignedUserId > 0) {
            $workflowIds = WorkflowLead::query()
                ->join('workflows', 'workflows.id', '=', 'workflow_leads.workflow_id')
                ->where('workflows.workspace_id', $workspace->id)
                ->where('workflow_leads.assigned_user_id', $assignedUserId)
                ->whereNotIn('workflow_leads.status', ['failed', 'rejected'])
                ->whereNull('workflow_leads.last_contacted_at')
                ->where(function (Builder $attempts) {
                    $attempts->whereNull('workflow_leads.contact_attempts')
                        ->orWhere('workflow_leads.contact_attempts', '<=', 0);
                })
                ->where(function (Builder $disposition) {
                    $disposition->whereNull('workflow_leads.last_disposition')
                        ->orWhere('workflow_leads.last_disposition', '');
                })
                ->distinct()
                ->orderByDesc('workflow_leads.workflow_id')
                ->limit(80)
                ->pluck('workflow_leads.workflow_id')
                ->map(fn ($id) => (int) $id)
                ->filter()
                ->values()
                ->all();

            if ($workflowIds === []) {
                return [];
            }

            $counts = WorkflowLead::query()
                ->selectRaw('workflow_id, COUNT(*) as assigned_count')
                ->whereIn('workflow_id', $workflowIds)
                ->where('assigned_user_id', $assignedUserId)
                ->whereNull('last_contacted_at')
                ->where(function (Builder $attempts) {
                    $attempts->whereNull('contact_attempts')
                        ->orWhere('contact_attempts', '<=', 0);
                })
                ->where(function (Builder $disposition) {
                    $disposition->whereNull('last_disposition')
                        ->orWhere('last_disposition', '');
                })
                ->groupBy('workflow_id')
                ->pluck('assigned_count', 'workflow_id');

            return $workspace->workflows()
                ->whereIn('workflows.id', $workflowIds)
                ->where('workflows.agent_restricted', false)
                ->where(function ($visible) use ($assignedUserId) {
                    $visible->whereDoesntHave('agentAccess')
                        ->orWhereHas('agentAccess', fn ($access) => $access->where('user_id', $assignedUserId));
                })
                ->orderByDesc('workflows.id')
                ->get(['workflows.id', 'workflows.name', 'workflows.original_filename', 'workflows.total_leads'])
                ->map(function ($workflow) use ($counts) {
                    $label = trim((string) ($workflow->original_filename ?: $workflow->name ?: 'Import #'.$workflow->id));
                    $assigned = (int) ($counts[$workflow->id] ?? 0);

                    return [
                        'id' => (int) $workflow->id,
                        'name' => $label,
                        'total_leads' => $assigned > 0 ? $assigned : (int) ($workflow->total_leads ?? 0),
                    ];
                })
                ->values()
                ->all();
        }

        return $workspace->workflows()
            ->orderByDesc('workflows.id')
            ->get(['workflows.id', 'workflows.name', 'workflows.original_filename', 'workflows.total_leads', 'workflows.agent_restricted'])
            ->map(function ($workflow) {
                $label = trim((string) ($workflow->original_filename ?: $workflow->name ?: 'Import #'.$workflow->id));

                return [
                    'id' => (int) $workflow->id,
                    'name' => $label,
                    'total_leads' => (int) ($workflow->total_leads ?? 0),
                    'agent_restricted' => (bool) ($workflow->agent_restricted ?? false),
                ];
            })
            ->all();
    }

    public function findLead(Workspace $workspace, int $leadId, ?int $ownerUserId = null): ?array
    {
        $query = WorkflowLead::query()
            ->select($this->selectColumns())
            ->join('workflows', 'workflows.id', '=', 'workflow_leads.workflow_id')
            ->where('workflows.workspace_id', $workspace->id)
            ->whereKey($leadId);

        // Agents may only open leads currently assigned to them.
        if ($ownerUserId !== null && $ownerUserId > 0) {
            $query->where('workflow_leads.assigned_user_id', $ownerUserId);
        }

        $lead = $query->first();

        if (! $lead) {
            return null;
        }

        $formatted = $this->formatLead($lead);

        return filled($formatted['phone'] ?? null) ? $formatted : null;
    }

    /**
     * True when this user is the active owner of the lead.
     */
    public function userOwnsLead(Workspace $workspace, int $leadId, int $userId): bool
    {
        return WorkflowLead::query()
            ->join('workflows', 'workflows.id', '=', 'workflow_leads.workflow_id')
            ->where('workflows.workspace_id', $workspace->id)
            ->where('workflow_leads.id', $leadId)
            ->where('workflow_leads.assigned_user_id', $userId)
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    public function dispositionToSetterStatus(string $disposition): ?string
    {
        return match (strtolower(trim($disposition))) {
            'no answer', 'no_answer', 'no-answer' => 'contacted',
            'answering machine', 'answer machine', 'answering_machine', 'answering-machine', 'answer_machine', 'voicemail' => 'contacted',
            'call back', 'call_back', 'call-back', 'call me later', 'call_me_later', 'call-me-later' => 'follow_up',
            'corporate business', 'corporate_business' => 'contacted',
            'owner not available', 'owner_not_available', 'not available', 'not_available', 'not-available' => 'contacted',
            'wrong number/business', 'wrong number', 'incorrect number', 'incorrect_number', 'incorrect-number' => 'not_interested',
            'owner hung up', 'owner hang up', 'owner hangup', 'owner_hangup' => 'contacted',
            'follow up', 'follow_up', 'follow-up' => 'follow_up',
            'requested appointment', 'requested_appointment', 'requested-appointment' => 'appointment_settled',
            'not interested', 'not_interested', 'not-interested' => 'not_interested',
            'gatekeeper', 'gate keeper', 'gate_keeper', 'gate-keeper' => 'contacted',
            default => null,
        };
    }

    /**
     * Mark a lead (and other undialed workspace leads with the same phone) as already dialed
     * so they leave the admin + agent imported-leads queues.
     *
     * @return list<int> Updated workflow_lead ids
     */
    public function markDialed(
        Workspace $workspace,
        ?WorkflowLead $lead,
        ?string $phone = null,
        ?string $disposition = null,
        ?string $note = null,
    ): array {
        $now = now();
        $dispositionValue = filled($disposition) ? mb_substr(trim($disposition), 0, 120) : null;
        $setterStatus = $dispositionValue
            ? $this->dispositionToSetterStatus($dispositionValue)
            : null;
        $phoneDigits = $this->phoneTail($phone ?: ($lead?->normalized_phone ?: $lead?->input_phone ?: $lead?->direct_phone));

        $ids = [];
        if ($lead) {
            $ids[] = (int) $lead->id;
        }

        if ($phoneDigits !== '') {
            $siblingIds = WorkflowLead::query()
                ->whereHas('workflow', fn ($q) => $q->where('workspace_id', $workspace->id))
                ->where(function (Builder $match) use ($phoneDigits) {
                    // Prefer exact / suffix matches so normalized_phone index can help.
                    $match->where('normalized_phone', $phoneDigits)
                        ->orWhere('normalized_phone', 'like', '%'.$phoneDigits)
                        ->orWhere('direct_phone', 'like', '%'.$phoneDigits)
                        ->orWhere('input_phone', 'like', '%'.$phoneDigits);
                })
                ->where(function (Builder $undialed) {
                    $undialed
                        ->whereNull('last_contacted_at')
                        ->where(function (Builder $attempts) {
                            $attempts->whereNull('contact_attempts')
                                ->orWhere('contact_attempts', '<=', 0);
                        })
                        ->where(function (Builder $disposition) {
                            $disposition->whereNull('last_disposition')
                                ->orWhere('last_disposition', '');
                        });
                })
                ->limit(40)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
            $ids = array_values(array_unique([...$ids, ...$siblingIds]));
        }

        if ($ids === []) {
            return [];
        }

        $baseUpdates = [
            'last_contacted_at' => $now,
        ];
        if ($dispositionValue) {
            $baseUpdates['last_disposition'] = $dispositionValue;
        }
        if ($setterStatus) {
            $baseUpdates['setter_status'] = $setterStatus;
        }

        // One bulk touch for queue removal — avoid N round-trips on Save.
        WorkflowLead::query()
            ->whereIn('id', $ids)
            ->update(array_merge($baseUpdates, [
                'contact_attempts' => \Illuminate\Support\Facades\DB::raw(
                    'CASE WHEN COALESCE(contact_attempts, 0) < 1 THEN 1 ELSE contact_attempts + 1 END'
                ),
            ]));

        if ($note && $lead && in_array((int) $lead->id, $ids, true)) {
            $row = WorkflowLead::query()->whereKey($lead->id)->first();
            if ($row) {
                $row->update([
                    'notes' => trim(((string) ($row->notes ?? ''))."\n".$note),
                ]);
            }
        }

        return $ids;
    }

    protected function phoneTail(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) ($phone ?? '')) ?? '';
        if (strlen($digits) >= 10) {
            return substr($digits, -10);
        }

        return $digits;
    }

    /**
     * @return Builder<WorkflowLead>
     */
    protected function baseQuery(Workspace $workspace, array $filters): Builder
    {
        $query = WorkflowLead::query()
            ->select($this->selectColumns())
            ->join('workflows', 'workflows.id', '=', 'workflow_leads.workflow_id')
            ->where('workflows.workspace_id', $workspace->id)
            ->whereNotIn('workflow_leads.status', ['failed', 'rejected'])
            ->where(function (Builder $phone) {
                // Real dialable numbers only — text like "Not Publicly Available" must not fill pages.
                $phone->where(function (Builder $normalized) {
                    $normalized->whereNotNull('workflow_leads.normalized_phone')
                        ->where('workflow_leads.normalized_phone', '!=', '')
                        ->whereRaw("workflow_leads.normalized_phone REGEXP '[0-9]{10}'");
                })->orWhere(function (Builder $direct) {
                    $direct->whereNotNull('workflow_leads.direct_phone')
                        ->where('workflow_leads.direct_phone', '!=', '')
                        ->where('workflow_leads.direct_phone', 'not like', '%Not Publicly%')
                        ->whereRaw("workflow_leads.direct_phone REGEXP '[0-9]{10}'");
                })->orWhere(function (Builder $input) {
                    $input->whereNotNull('workflow_leads.input_phone')
                        ->where('workflow_leads.input_phone', '!=', '')
                        ->where('workflow_leads.input_phone', 'not like', '%Not Publicly%')
                        ->whereRaw("workflow_leads.input_phone REGEXP '[0-9]{10}'");
                });
            })
            // Already dialed / dispositioned leads never return to the imported-leads queue
            // (admin auto dial and agent assigned queue share this rule).
            ->where(function (Builder $undialed) {
                $undialed
                    ->whereNull('workflow_leads.last_contacted_at')
                    ->where(function (Builder $attempts) {
                        $attempts->whereNull('workflow_leads.contact_attempts')
                            ->orWhere('workflow_leads.contact_attempts', '<=', 0);
                    })
                    ->where(function (Builder $disposition) {
                        $disposition->whereNull('workflow_leads.last_disposition')
                            ->orWhere('workflow_leads.last_disposition', '');
                    });
            })
            ->with(['campaign:id,name', 'workflow:id,name,original_filename'])
            // Oldest first at the top; auto-dial starts from the top and works down.
            ->orderBy('workflow_leads.id');

        if (filled($filters['campaign_id'] ?? null)) {
            $query->where('workflow_leads.campaign_id', (int) $filters['campaign_id']);
        }

        $workflowIds = $filters['workflow_ids'] ?? null;
        if (is_array($workflowIds)) {
            $ids = array_values(array_filter(array_map('intval', $workflowIds)));
            if ($ids !== []) {
                $query->whereIn('workflow_leads.workflow_id', $ids);
            }
        } elseif (filled($filters['workflow_id'] ?? null)) {
            $query->where('workflow_leads.workflow_id', (int) $filters['workflow_id']);
        }

        if (filled($filters['assigned_user_id'] ?? null)) {
            // Active owner only — never expose another agent's queue via historical assigned_setter_id.
            $query->where('workflow_leads.assigned_user_id', (int) $filters['assigned_user_id']);
            // Admin-restricted sheets stay hidden from the agent dialer queue.
            $query->where('workflows.agent_restricted', false);
            $agentId = (int) $filters['assigned_user_id'];
            $query->where(function (Builder $visible) use ($agentId) {
                $visible->whereNotExists(function ($sub) {
                    $sub->selectRaw('1')
                        ->from('workflow_agent_access')
                        ->whereColumn('workflow_agent_access.workflow_id', 'workflows.id');
                })->orWhereExists(function ($sub) use ($agentId) {
                    $sub->selectRaw('1')
                        ->from('workflow_agent_access')
                        ->whereColumn('workflow_agent_access.workflow_id', 'workflows.id')
                        ->where('workflow_agent_access.user_id', $agentId);
                });
            });
        }

        $assignedUserIds = $filters['assigned_user_ids'] ?? null;
        if (is_array($assignedUserIds) && $assignedUserIds !== []) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $assignedUserIds))));
            if ($ids !== []) {
                $query->whereIn('workflow_leads.assigned_user_id', $ids);
            }
        }

        if (filled($filters['search'] ?? null)) {
            $term = '%'.trim((string) $filters['search']).'%';
            $query->where(function (Builder $search) use ($term) {
                $search->where('workflow_leads.business_name', 'like', $term)
                    ->orWhere('workflow_leads.owner_name', 'like', $term)
                    ->orWhere('workflow_leads.input_phone', 'like', $term)
                    ->orWhere('workflow_leads.direct_phone', 'like', $term)
                    ->orWhere('workflow_leads.normalized_phone', 'like', $term);
            });
        }

        return match ((string) ($filters['pool'] ?? 'all')) {
            'assigned' => $query->whereNotNull('workflow_leads.assigned_user_id'),
            'unassigned' => $query->whereNull('workflow_leads.assigned_user_id')
                ->whereNull('workflow_leads.assigned_setter_id'),
            'callable' => $query->where(function (Builder $callable) {
                $callable->whereNull('workflow_leads.setter_status')
                    ->orWhereNotIn('workflow_leads.setter_status', ['appointment_settled', 'not_interested']);
            }),
            default => $query,
        };
    }

    /**
     * @return list<string>
     */
    protected function selectColumns(): array
    {
        return [
            'workflow_leads.id',
            'workflow_leads.workflow_id',
            'workflow_leads.campaign_id',
            'workflow_leads.business_name',
            'workflow_leads.owner_name',
            'workflow_leads.city',
            'workflow_leads.state',
            'workflow_leads.normalized_phone',
            'workflow_leads.direct_phone',
            'workflow_leads.input_phone',
            'workflow_leads.markdown_report',
            'workflow_leads.raw_row',
            'workflow_leads.setter_status',
            'workflow_leads.assigned_user_id',
            'workflow_leads.last_contacted_at',
            'workflow_leads.last_disposition',
            'workflow_leads.contact_attempts',
            'workflow_leads.tags',
            'workflow_leads.segment',
            'workflow_leads.website',
            'workflow_leads.input_email',
            'workflow_leads.direct_email',
            'workflow_leads.payment_processor',
            'workflow_leads.updated_at',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatLead(WorkflowLead $lead): array
    {
        $phone = $this->resolvePhone($lead);
        $name = $this->resolveName($lead);
        $contact = $this->resolveContact($lead, $name);
        $fileName = $this->resolveFileName($lead);
        $state = \App\Support\UsAreaCodeState::resolve(
            $lead->state,
            $phone ?: ($lead->normalized_phone ?: $lead->input_phone)
        );
        $display = LeadContactDisplay::for($lead);
        $tags = collect(is_array($lead->tags) ? $lead->tags : [])
            ->map(fn ($tag) => trim((string) $tag))
            ->filter()
            ->values()
            ->take(6)
            ->all();
        $extra = array_values(array_filter([
            filled($display['email'] ?? null) ? 'Email: '.$display['email'] : null,
            filled($display['website'] ?? null) ? 'Web: '.$display['website'] : null,
            filled($display['social_media'] ?? null) ? 'Social: '.$display['social_media'] : null,
            filled($display['processor'] ?? null) ? 'Processor: '.$display['processor'] : null,
            filled($lead->segment) ? 'Segment: '.$lead->segment : null,
        ]));

        $lastDialedLabel = null;
        if ($lead->last_contacted_at) {
            $lastDialedLabel = $lead->last_contacted_at->timezone(config('app.timezone'))->diffForHumans();
        }

        return [
            'id' => (int) $lead->id,
            'name' => $name,
            'contact' => $contact,
            'owner_name' => $contact,
            'phone' => $phone,
            'phone_display' => CommunicationsLeadLookupService::formatPhoneDisplay($phone),
            'city' => trim((string) ($lead->city ?? '')) ?: null,
            'state' => $state,
            'campaign' => $lead->campaign?->name,
            'workflow' => $lead->workflow?->name,
            'file_name' => $fileName,
            'setter_status' => $lead->setter_status,
            // Only real dial dispositions — never mirror setter_status ("new") here or the
            // agent dialer client filters every undialed lead out of the queue.
            'last_disposition' => filled($lead->last_disposition)
                ? (string) $lead->last_disposition
                : null,
            'last_contacted_at' => $lead->last_contacted_at?->toIso8601String(),
            'last_dialed_label' => $lastDialedLabel,
            'contact_attempts' => (int) ($lead->contact_attempts ?? 0),
            'tags' => $tags,
            'segment' => filled($lead->segment) ? (string) $lead->segment : null,
            'extra_fields' => $extra,
            'email' => $display['email'] ?? null,
            'website' => $display['website'] ?? null,
            'assigned' => $lead->assigned_user_id !== null,
        ];
    }

    protected function resolveFileName(WorkflowLead $lead): string
    {
        $original = trim((string) ($lead->workflow?->original_filename ?? ''));
        if ($original !== '') {
            return $original;
        }

        return trim((string) ($lead->workflow?->name ?? ''));
    }

    protected function resolvePhone(WorkflowLead $lead): ?string
    {
        // Prefer the sheet Contact Number / input_phone — never owner or unrelated DIDs.
        $candidates = [
            $lead->input_phone,
            $lead->normalized_phone ? UsPhoneNormalizer::e164($lead->normalized_phone) : null,
            LeadContactDisplay::value($lead, 'phone'),
            $lead->direct_phone,
        ];

        foreach ($candidates as $candidate) {
            $raw = trim((string) ($candidate ?? ''));
            if ($raw === '' || str_contains(strtolower($raw), 'not publicly')) {
                continue;
            }
            if (LeadContactDisplay::looksLikePhoneNumber($raw) === false
                && strlen(preg_replace('/\D/', '', $raw) ?? '') < 10) {
                continue;
            }
            $normalized = UsPhoneNormalizer::e164($raw) ?? $raw;
            if ($normalized !== '' && strlen(preg_replace('/\D/', '', $normalized) ?? '') >= 10) {
                return $normalized;
            }
        }

        return null;
    }

    protected function resolveName(WorkflowLead $lead): string
    {
        $business = LeadContactDisplay::label($lead->business_name, '');

        if ($business !== '' && $business !== '—') {
            return $business;
        }

        return '';
    }

    protected function resolveContact(WorkflowLead $lead, string $name): string
    {
        $candidates = [
            $lead->owner_name,
            LeadContactDisplay::value($lead, 'owner'),
        ];

        foreach ($candidates as $candidate) {
            $owner = LeadContactDisplay::label((string) ($candidate ?? ''), '');
            if ($owner === '' || $owner === '—') {
                continue;
            }
            if ($name !== '' && strcasecmp($owner, $name) === 0) {
                continue;
            }

            return $owner;
        }

        return '';
    }
}
