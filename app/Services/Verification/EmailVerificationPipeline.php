<?php

namespace App\Services\Verification;

use App\Models\EmailContact;
use App\Models\VerificationResult;

class EmailVerificationPipeline
{
    public function __construct(
        protected SyntaxValidator $syntaxValidator,
        protected MxRecordChecker $mxRecordChecker,
        protected DisposableDomainChecker $disposableChecker,
        protected RoleAccountDetector $roleDetector,
        protected FreeProviderDetector $freeProviderDetector,
        protected SmtpVerifier $smtpVerifier,
    ) {}

    public function verify(EmailContact $contact): void
    {
        $email = $contact->normalized_email;
        $domain = $contact->domain ?? (explode('@', $email)[1] ?? '');

        $tags = [];
        $isRisky = false;
        $failureReason = null;
        $smtpSkipped = true;
        $smtpUnknown = false;

        $stages = [
            'syntax' => fn () => $this->syntaxValidator->validate($email),
            'mx' => fn () => $this->mxRecordChecker->check($domain),
            'disposable' => fn () => $this->disposableChecker->check($domain),
            'role' => fn () => $this->roleDetector->check($email),
            'free_provider' => fn () => $this->freeProviderDetector->check($domain),
            'smtp' => fn () => $this->smtpVerifier->verify($email),
        ];

        foreach ($stages as $stage => $checker) {
            $start = microtime(true);
            $result = $checker();
            $durationMs = (int) ((microtime(true) - $start) * 1000);

            VerificationResult::create([
                'email_contact_id' => $contact->id,
                'stage' => $stage,
                'status' => $result['status'],
                'message' => $result['message'],
                'metadata' => $result['metadata'] ?? null,
                'duration_ms' => $durationMs,
            ]);

            if ($stage === 'syntax' || $stage === 'mx' || $stage === 'disposable') {
                if (! $result['passed'] || $result['status'] === 'invalid') {
                    $contact->update([
                        'status' => 'invalid',
                        'failure_reason' => $result['message'],
                        'final_score' => 0,
                    ]);

                    return;
                }
            }

            if ($stage === 'role' && $result['status'] === 'risky') {
                $isRisky = true;
                $tags[] = 'role_account';
            }

            if ($stage === 'free_provider' && ($result['metadata']['is_free_provider'] ?? false)) {
                $tags[] = 'free_provider';
            }

            if ($stage === 'smtp') {
                if ($result['status'] === 'skipped') {
                    $smtpSkipped = true;
                } elseif ($result['status'] === 'unknown') {
                    $smtpUnknown = true;
                    $isRisky = true;
                } elseif ($result['status'] === 'pass') {
                    $smtpSkipped = false;
                } else {
                    $contact->update([
                        'status' => 'invalid',
                        'failure_reason' => $result['message'],
                        'final_score' => 0,
                        'tags' => $tags,
                    ]);

                    return;
                }
            }
        }

        if ($smtpUnknown) {
            $status = 'unknown';
            $score = 50;
            $failureReason = 'SMTP verification inconclusive';
        } elseif ($isRisky) {
            $status = 'risky';
            $score = 60;
            $failureReason = 'Role account or inconclusive verification';
        } else {
            $status = 'valid';
            $score = $smtpSkipped ? 75 : 95;
            $failureReason = null;
        }

        $contact->update([
            'status' => $status,
            'final_score' => $score,
            'tags' => $tags,
            'failure_reason' => $failureReason,
        ]);
    }
}
