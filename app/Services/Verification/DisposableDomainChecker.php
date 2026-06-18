<?php

namespace App\Services\Verification;

use App\Models\DisposableDomain;

class DisposableDomainChecker
{
    public function check(string $domain): array
    {
        $domain = strtolower($domain);

        $isDisposable = DisposableDomain::where('domain', $domain)->exists();

        if ($isDisposable) {
            return [
                'passed' => false,
                'status' => 'invalid',
                'message' => 'Disposable/temporary email domain',
            ];
        }

        return [
            'passed' => true,
            'status' => 'pass',
            'message' => 'Not a known disposable domain',
        ];
    }
}
