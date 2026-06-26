<?php

namespace App\Services\SalesOps;

use App\Models\LeadActivity;
use App\Models\User;
use App\Models\WorkflowLead;
use App\Models\Workspace;
use App\Support\SalesOps;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DiscoveryQualificationService
{
    public function isComplete(WorkflowLead $lead): bool
    {
        return $this->missingFields($lead) === [];
    }

    /**
     * @return array<int, string>
     */
    public function missingFields(WorkflowLead $lead): array
    {
        $missing = [];

        if (! filled($lead->business_name)) {
            $missing[] = 'business_name';
        }
        if (! filled($lead->owner_name) || $lead->owner_name === 'Not Publicly Available') {
            $missing[] = 'owner_name';
        }
        if (! $this->hasPhone($lead)) {
            $missing[] = 'phone';
        }
        if (! $this->hasEmail($lead)) {
            $missing[] = 'email';
        }
        if (! $lead->monthly_processing_volume) {
            $missing[] = 'monthly_processing_volume';
        }
        if (! filled($lead->current_processor)) {
            $missing[] = 'current_processor';
        }
        if (! filled($lead->pricing_model)) {
            $missing[] = 'pricing_model';
        }
        if (! $lead->contract_expiration_date) {
            $missing[] = 'contract_expiration_date';
        }
        if (empty($lead->pain_points)) {
            $missing[] = 'pain_points';
        }

        return $missing;
    }

    public function meetingQualified(WorkflowLead $lead): bool
    {
        return filled($lead->owner_name)
            && $lead->monthly_processing_volume
            && filled($lead->current_processor)
            && ! empty($lead->pain_points);
    }

    public function markDiscoveryComplete(WorkflowLead $lead, User $user): WorkflowLead
    {
        if (! $this->isComplete($lead)) {
            return $lead;
        }

        $lead->update([
            'stage' => 'discovery_completed',
            'discovery_completed_at' => now(),
            'discovery_completed_by' => $user->id,
        ]);

        return $lead->fresh();
    }

    protected function hasPhone(WorkflowLead $lead): bool
    {
        $phone = $lead->direct_phone ?: $lead->input_phone;

        return filled($phone) && $phone !== 'Not Publicly Available';
    }

    protected function hasEmail(WorkflowLead $lead): bool
    {
        $email = $lead->direct_email ?: $lead->input_email;

        return filled($email) && $email !== 'Not Publicly Available';
    }
}
