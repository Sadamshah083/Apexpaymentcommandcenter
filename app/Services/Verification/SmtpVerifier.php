<?php

namespace App\Services\Verification;

use SMTPValidateEmail\Validator as SmtpValidator;

class SmtpVerifier
{
    public function verify(string $email): array
    {
        if (! config('email_checker.verification.smtp_enabled', false)) {
            return [
                'passed' => true,
                'status' => 'skipped',
                'message' => 'SMTP verification disabled (enable EMAIL_CHECKER_SMTP_ENABLED when port 25 is open)',
                'metadata' => ['skipped' => true],
            ];
        }

        try {
            $from = config('email_checker.verification.smtp_from_email', 'verify@example.com');
            $validator = new SmtpValidator([$email], $from);
            $validator->setConnectTimeout(config('email_checker.verification.smtp_timeout', 10));
            $validator->setStreamTimeout(config('email_checker.verification.smtp_timeout', 10));

            $results = $validator->validate();

            if (($results[$email] ?? false) === true) {
                return [
                    'passed' => true,
                    'status' => 'pass',
                    'message' => 'SMTP mailbox appears deliverable',
                    'metadata' => ['smtp_result' => 'deliverable'],
                ];
            }

            return [
                'passed' => false,
                'status' => 'unknown',
                'message' => 'SMTP verification inconclusive or mailbox not found',
                'metadata' => ['smtp_result' => 'undeliverable'],
            ];
        } catch (\Throwable $e) {
            return [
                'passed' => true,
                'status' => 'unknown',
                'message' => 'SMTP check failed: '.$e->getMessage(),
                'metadata' => ['error' => $e->getMessage()],
            ];
        }
    }
}
