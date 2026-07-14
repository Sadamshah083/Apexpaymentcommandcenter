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

    public function findLead(Workspace $workspace, int $leadId): ?array
    {
        $lead = WorkflowLead::query()
            ->select($this->selectColumns())
            ->join('workflows', 'workflows.id', '=', 'workflow_leads.workflow_id')
            ->where('workflows.workspace_id', $workspace->id)
            ->whereKey($leadId)
            ->first();

        if (! $lead) {
            return null;
        }

        $formatted = $this->formatLead($lead);

        return filled($formatted['phone'] ?? null) ? $formatted : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function dispositionToSetterStatus(string $disposition): ?string
    {
        return match (strtolower(trim($disposition))) {
            'no answer', 'no_answer', 'no-answer' => 'contacted',
            'answering machine', 'answering_machine', 'answering-machine' => 'contacted',
            'voicemail' => 'contacted',
            'follow up', 'follow_up', 'follow-up' => 'follow_up',
            'requested appointment', 'requested_appointment', 'requested-appointment' => 'appointment_settled',
            'not interested', 'not_interested', 'not-interested' => 'not_interested',
            'incorrect number', 'incorrect_number', 'incorrect-number' => 'not_interested',
            'not available', 'not_available', 'not-available' => 'contacted',
            'call me later', 'call_me_later', 'call-me-later' => 'follow_up',
            default => null,
        };
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
                $phone->whereNotNull('workflow_leads.normalized_phone')
                    ->orWhereNotNull('workflow_leads.direct_phone')
                    ->orWhereNotNull('workflow_leads.input_phone');
            })
            // Once dialed / dispositioned, never show again in the dialer imported-leads queue.
            ->whereNull('workflow_leads.last_contacted_at')
            ->where(function (Builder $attempts) {
                $attempts->whereNull('workflow_leads.contact_attempts')
                    ->orWhere('workflow_leads.contact_attempts', '<=', 0);
            })
            ->with(['campaign:id,name', 'workflow:id,name'])
            // Oldest first at the top; auto-dial starts from the top and works down.
            ->orderBy('workflow_leads.id');

        if (filled($filters['campaign_id'] ?? null)) {
            $query->where('workflow_leads.campaign_id', (int) $filters['campaign_id']);
        }

        if (filled($filters['assigned_user_id'] ?? null)) {
            $query->where('workflow_leads.assigned_user_id', (int) $filters['assigned_user_id']);
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
            'unassigned' => $query->whereNull('workflow_leads.assigned_user_id'),
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
            'workflow_leads.normalized_phone',
            'workflow_leads.direct_phone',
            'workflow_leads.input_phone',
            'workflow_leads.markdown_report',
            'workflow_leads.raw_row',
            'workflow_leads.setter_status',
            'workflow_leads.assigned_user_id',
            'workflow_leads.last_contacted_at',
            'workflow_leads.contact_attempts',
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

        return [
            'id' => (int) $lead->id,
            'name' => $name,
            'contact' => $contact,
            'phone' => $phone,
            'phone_display' => CommunicationsLeadLookupService::formatPhoneDisplay($phone),
            'campaign' => $lead->campaign?->name,
            'workflow' => $lead->workflow?->name,
            'setter_status' => $lead->setter_status,
            'assigned' => $lead->assigned_user_id !== null,
            'last_contacted_at' => $lead->last_contacted_at?->toIso8601String(),
        ];
    }

    protected function resolvePhone(WorkflowLead $lead): ?string
    {
        $candidates = [
            $lead->normalized_phone ? UsPhoneNormalizer::e164($lead->normalized_phone) : null,
            LeadContactDisplay::value($lead, 'phone'),
            $lead->direct_phone,
            $lead->input_phone,
        ];

        foreach ($candidates as $candidate) {
            $normalized = UsPhoneNormalizer::e164($candidate) ?? trim((string) $candidate);
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
        $owner = LeadContactDisplay::label(LeadContactDisplay::value($lead, 'owner'), '');

        if ($owner === '' || $owner === '—') {
            return '';
        }

        if ($name !== '' && strcasecmp($owner, $name) === 0) {
            return '';
        }

        return $owner;
    }
}
