<?php

namespace App\Services\Verification;

use App\Models\FreeProviderDomain;
use Illuminate\Support\Facades\Cache;

class FreeProviderDetector
{
    public function check(string $domain): array
    {
        $domain = strtolower($domain);
        $providers = Cache::remember('free_provider_domains', 3600, fn () => FreeProviderDomain::pluck('domain')->all());

        if (in_array($domain, $providers, true)) {
            return [
                'passed' => true,
                'status' => 'pass',
                'message' => 'Free email provider',
                'metadata' => ['is_free_provider' => true, 'provider' => $domain],
            ];
        }

        return [
            'passed' => true,
            'status' => 'pass',
            'message' => 'Business/custom domain',
            'metadata' => ['is_free_provider' => false],
        ];
    }
}
