<?php

namespace App\Services\Verification;

use Egulias\EmailValidator\EmailValidator;
use Egulias\EmailValidator\Validation\MultipleValidationWithAnd;
use Egulias\EmailValidator\Validation\RFCValidation;

class SyntaxValidator
{
    public function validate(string $email): array
    {
        $validator = new EmailValidator;
        $validations = new MultipleValidationWithAnd([
            new RFCValidation,
        ]);

        if (! $validator->isValid($email, $validations)) {
            return [
                'passed' => false,
                'status' => 'invalid',
                'message' => 'Invalid email syntax (RFC 5322)',
            ];
        }

        if (preg_match('/\.{2,}/', $email)) {
            return [
                'passed' => false,
                'status' => 'invalid',
                'message' => 'Email contains consecutive dots',
            ];
        }

        if (preg_match('/@(gmail|googlemail)\.com$/i', $email) && str_contains($email, '+')) {
            // Gmail plus addressing is valid
        }

        return [
            'passed' => true,
            'status' => 'pass',
            'message' => 'Valid email syntax',
        ];
    }
}
