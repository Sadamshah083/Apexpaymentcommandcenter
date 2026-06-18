<?php

namespace App\Services\Workflow;

use App\Http\Controllers\PushNotificationController;
use App\Models\WorkflowLead;
use App\Services\Content\ContentRuleEngine;
use App\Services\Deliverability\DeliverabilityAnalyzer;
use App\Services\Workspace\WorkspaceSyncService;
use App\Services\Verification\DisposableDomainChecker;
use App\Services\Verification\MxRecordChecker;
use App\Services\Verification\SmtpVerifier;
use App\Services\Verification\SyntaxValidator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WorkflowLeadService
{
    public function __construct(
        protected SyntaxValidator $syntaxValidator,
        protected MxRecordChecker $mxRecordChecker,
        protected DisposableDomainChecker $disposableDomainChecker,
        protected SmtpVerifier $smtpVerifier,
        protected ContentRuleEngine $contentRuleEngine,
        protected DeliverabilityAnalyzer $deliverabilityAnalyzer,
        protected WorkspaceSyncService $syncService,
    ) {}

    public function update(WorkflowLead $lead, array $data): void
    {
        $oldAssignee = $lead->assigned_user_id;

        $lead->update($data);
        $lead->loadMissing('workflow.workspace');

        $eventType = ($lead->assigned_user_id && $lead->assigned_user_id != $oldAssignee)
            ? 'lead.assigned'
            : 'lead.updated';

        $this->syncService->record(
            $lead->workflow->workspace,
            $eventType,
            'workflow_lead',
            $lead->id,
            [
                'business_name' => $lead->business_name,
                'stage' => $lead->stage,
                'assigned_user_id' => $lead->assigned_user_id,
            ],
            auth()->id()
        );

        if ($lead->assigned_user_id && $lead->assigned_user_id != $oldAssignee) {
            try {
                PushNotificationController::sendPushNotification(
                    $lead->assigned_user_id,
                    'Lead Reassigned to You',
                    'You have been assigned lead: '.$lead->business_name,
                    route('portal.leads.show', $lead->id)
                );
            } catch (\Throwable $e) {
                Log::warning('Lead reassignment push notification failed', [
                    'lead_id' => $lead->id,
                    'assignee_id' => $lead->assigned_user_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function delete(WorkflowLead $lead): void
    {
        $lead->loadMissing('workflow.workspace');
        $workspace = $lead->workflow->workspace;
        $workflow = $lead->workflow;
        $leadId = $lead->id;
        $leadStatus = $lead->status;
        $wasCounted = in_array($leadStatus, ['completed', 'failed'], true);

        DB::transaction(function () use ($lead, $workflow, $wasCounted, $leadStatus) {
            $lead->delete();

            if ($wasCounted) {
                if ($leadStatus === 'completed') {
                    $workflow->decrement('processed_leads');
                } else {
                    $workflow->decrement('failed_leads');
                }
            }

            $remaining = $workflow->leads()->count();
            $workflow->update([
                'total_leads' => max(0, $remaining),
            ]);

            if ($remaining === 0 && in_array($workflow->status, ['extracting', 'paused', 'completed'], true)) {
                $workflow->update(['status' => 'completed']);
            } elseif (
                $remaining > 0
                && $workflow->total_leads > 0
                && ($workflow->processed_leads + $workflow->failed_leads) >= $workflow->total_leads
                && $workflow->status !== 'completed'
            ) {
                $workflow->update(['status' => 'completed']);
            }
        });

        $this->syncService->record(
            $workspace,
            'lead.deleted',
            'workflow_lead',
            $leadId,
            [],
            auth()->id()
        );
    }

    public function verifyEmail(WorkflowLead $lead, ?string $email = null): array
    {
        $email = $email ?? $lead->direct_email ?: $lead->input_email;

        if (! $email || $email === 'Not Publicly Available') {
            throw new HttpResponseException(response()->json([
                'error' => 'No valid email address found on lead.',
            ], 400));
        }

        $domain = explode('@', $email)[1] ?? '';

        return [
            'email' => $email,
            'syntax' => $this->syntaxValidator->validate($email),
            'mx' => $this->mxRecordChecker->check($domain),
            'disposable' => $this->disposableDomainChecker->check($domain),
            'smtp' => $this->smtpVerifier->verify($email),
        ];
    }

    public function analyzeEmailContent(?string $subject, ?string $body): array
    {
        $subject = $subject ?? '';
        $body = $body ?? '';

        if (! $subject && ! $body) {
            throw new HttpResponseException(response()->json([
                'error' => 'Please provide a subject or body to analyze.',
            ], 400));
        }

        return $this->contentRuleEngine->analyze($subject, $body, $body);
    }

    public function checkDomain(WorkflowLead $lead, ?string $domain = null): array
    {
        $domain = $domain ?? $lead->website;

        if (! $domain || $domain === 'Not Publicly Available') {
            throw new HttpResponseException(response()->json([
                'error' => 'No website domain found on lead.',
            ], 400));
        }

        $domain = preg_replace('/^https?:\/\/(www\.)?/', '', strtolower(trim($domain)));
        $domain = explode('/', $domain)[0];

        return $this->deliverabilityAnalyzer->analyze($domain);
    }
}
