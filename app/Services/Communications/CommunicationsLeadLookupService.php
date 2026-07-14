<?php

namespace App\Services\Communications;

use App\Models\WorkflowLead;
use App\Models\Workspace;
use App\Support\LeadContactDisplay;
use App\Support\UsPhoneNormalizer;

class CommunicationsLeadLookupService
{
    public function __construct(
        protected CommunicationsPhoneNotesService $phoneNotes,
    ) {}

    /**
     * @param  array<int, string|null>  $phones
     * @return array<string, array{name: string, contact: ?string, lead_id: int, file_name: ?string}>
     */
    public function mapLabelsForPhones(Workspace $workspace, array $phones): array
    {
        $keys = collect($phones)
            ->map(fn (?string $phone) => $this->phoneNotes->normalizePhoneKey($phone))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($keys === []) {
            return [];
        }

        $map = [];

        $primaryMatches = WorkflowLead::query()
            ->select([
                'workflow_leads.id',
                'workflow_leads.business_name',
                'workflow_leads.owner_name',
                'workflow_leads.normalized_phone',
                'workflow_leads.direct_phone',
                'workflow_leads.input_phone',
                'workflow_leads.markdown_report',
                'workflow_leads.raw_row',
                'workflow_leads.updated_at',
                'workflow_leads.workflow_id',
            ])
            ->with(['workflow:id,name,original_filename'])
            ->join('workflows', 'workflows.id', '=', 'workflow_leads.workflow_id')
            ->where('workflows.workspace_id', $workspace->id)
            ->whereIn('workflow_leads.normalized_phone', $keys)
            ->orderByDesc('workflow_leads.updated_at')
            ->get();

        foreach ($primaryMatches as $lead) {
            $key = (string) ($lead->normalized_phone ?? '');
            if ($key === '' || isset($map[$key])) {
                continue;
            }

            $label = $this->buildLabel($lead);
            if ($label['name'] !== '') {
                $map[$key] = $label;
            }
        }

        $missing = array_values(array_diff($keys, array_keys($map)));
        if ($missing === []) {
            return $map;
        }

        $fallbackMatches = WorkflowLead::query()
            ->select([
                'workflow_leads.id',
                'workflow_leads.business_name',
                'workflow_leads.owner_name',
                'workflow_leads.normalized_phone',
                'workflow_leads.direct_phone',
                'workflow_leads.input_phone',
                'workflow_leads.markdown_report',
                'workflow_leads.raw_row',
                'workflow_leads.updated_at',
                'workflow_leads.workflow_id',
            ])
            ->with(['workflow:id,name,original_filename'])
            ->join('workflows', 'workflows.id', '=', 'workflow_leads.workflow_id')
            ->where('workflows.workspace_id', $workspace->id)
            ->where(function ($query) {
                $query->whereNotNull('workflow_leads.direct_phone')
                    ->orWhereNotNull('workflow_leads.input_phone');
            })
            ->orderByDesc('workflow_leads.updated_at')
            ->limit(400)
            ->get();

        foreach ($fallbackMatches as $lead) {
            $candidateKeys = collect([
                $lead->direct_phone,
                $lead->input_phone,
                LeadContactDisplay::value($lead, 'phone'),
            ])
                ->map(fn (?string $phone) => $this->phoneNotes->normalizePhoneKey($phone))
                ->filter()
                ->unique()
                ->values();

            foreach ($candidateKeys as $key) {
                if (! in_array($key, $missing, true) || isset($map[$key])) {
                    continue;
                }

                $label = $this->buildLabel($lead);
                if ($label['name'] !== '') {
                    $map[$key] = $label;
                }
            }

            if (count($map) === count($keys)) {
                break;
            }
        }

        return $map;
    }

    public static function callbackPhoneFromLog(array $log): ?string
    {
        $phone = match ($log['direction'] ?? '') {
            'inbound' => $log['from_phone'] ?? null,
            'outbound' => $log['to_phone'] ?? null,
            default => $log['to_phone'] ?? ($log['from_phone'] ?? null),
        };

        return filled($phone) ? (string) $phone : null;
    }

    public static function formatPhoneDisplay(?string $phone): ?string
    {
        if (! filled($phone)) {
            return null;
        }

        $normalized = UsPhoneNormalizer::normalize($phone);

        return UsPhoneNormalizer::format($normalized)
            ?? UsPhoneNormalizer::e164($phone)
            ?? trim($phone);
    }

    /**
     * @return array{name: string, contact: ?string, lead_id: int, file_name: ?string}
     */
    protected function buildLabel(WorkflowLead $lead): array
    {
        $business = LeadContactDisplay::label($lead->business_name, '');
        $owner = LeadContactDisplay::label(
            $lead->owner_name ?: LeadContactDisplay::value($lead, 'owner'),
            ''
        );

        $name = '';
        $contact = null;

        if ($business !== '' && $business !== '—') {
            $name = $business;
            if ($owner !== '' && $owner !== '—' && strcasecmp($owner, $business) !== 0) {
                $contact = $owner;
            }
        } elseif ($owner !== '' && $owner !== '—') {
            $name = $owner;
        }

        $fileName = trim((string) ($lead->workflow?->original_filename ?: $lead->workflow?->name ?: ''));

        return [
            'name' => $name,
            'contact' => $contact,
            'lead_id' => (int) $lead->id,
            'file_name' => $fileName !== '' ? $fileName : null,
        ];
    }
}
