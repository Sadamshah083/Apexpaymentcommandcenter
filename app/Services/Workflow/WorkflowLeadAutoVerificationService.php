<?php

namespace App\Services\Workflow;

use App\Models\Workflow;
use App\Models\WorkflowLead;
use App\Services\Deliverability\DeliverabilityAnalyzer;
use App\Services\Verification\DisposableDomainChecker;
use App\Services\Verification\MxRecordChecker;
use App\Services\Verification\SmtpVerifier;
use App\Services\Verification\SyntaxValidator;
use Illuminate\Support\Facades\Log;

class WorkflowLeadAutoVerificationService
{
    public function __construct(
        protected SyntaxValidator $syntaxValidator,
        protected MxRecordChecker $mxRecordChecker,
        protected DisposableDomainChecker $disposableDomainChecker,
        protected SmtpVerifier $smtpVerifier,
        protected DeliverabilityAnalyzer $deliverabilityAnalyzer,
    ) {}

    /**
     * Run automated checks configured on the workflow and return a snapshot for human review.
     */
    public function run(WorkflowLead $lead, Workflow $workflow): array
    {
        $toggles = $workflow->verification_toggles ?? ['email' => '1', 'domain' => '1'];
        $snapshot = [
            'ran_at' => now()->toIso8601String(),
            'toggles' => $toggles,
            'email' => null,
            'domain' => null,
        ];

        if ($this->toggleEnabled($toggles, 'email')) {
            $snapshot['email'] = $this->runEmailCheck($lead);
        }

        if ($this->toggleEnabled($toggles, 'domain')) {
            $snapshot['domain'] = $this->runDomainCheck($lead);
        }

        return $snapshot;
    }

    protected function toggleEnabled(array $toggles, string $key): bool
    {
        if (! array_key_exists($key, $toggles)) {
            return true;
        }

        $value = $toggles[$key];

        return $value === true || $value === 1 || $value === '1' || $value === 'on';
    }

    protected function runEmailCheck(WorkflowLead $lead): array
    {
        $email = $lead->direct_email ?: $lead->input_email;

        if (! $email || $email === 'Not Publicly Available') {
            return ['skipped' => true, 'reason' => 'No email address on lead'];
        }

        try {
            $domain = explode('@', $email)[1] ?? '';

            return [
                'email' => $email,
                'syntax' => $this->syntaxValidator->validate($email),
                'mx' => $this->mxRecordChecker->check($domain),
                'disposable' => $this->disposableDomainChecker->check($domain),
                'smtp' => $this->smtpVerifier->verify($email),
            ];
        } catch (\Throwable $e) {
            Log::warning('Auto email verification failed', [
                'lead_id' => $lead->id,
                'error' => $e->getMessage(),
            ]);

            return ['error' => $e->getMessage()];
        }
    }

    protected function runDomainCheck(WorkflowLead $lead): array
    {
        $domain = $lead->website;

        if (! $domain || $domain === 'Not Publicly Available') {
            return ['skipped' => true, 'reason' => 'No website domain on lead'];
        }

        try {
            $domain = preg_replace('/^https?:\/\/(www\.)?/', '', strtolower(trim($domain)));
            $domain = explode('/', $domain)[0];

            return $this->deliverabilityAnalyzer->analyze($domain);
        } catch (\Throwable $e) {
            Log::warning('Auto domain verification failed', [
                'lead_id' => $lead->id,
                'error' => $e->getMessage(),
            ]);

            return ['error' => $e->getMessage()];
        }
    }
}
