<?php

namespace App\Services\Verification;

use App\Models\RolePrefix;
use Illuminate\Support\Facades\Cache;

class RoleAccountDetector
{
    public function check(string $email): array
    {
        $localPart = strtolower(explode('@', $email)[0] ?? '');
        $prefixes = Cache::remember('role_prefixes', 3600, fn () => RolePrefix::pluck('prefix')->all());

        foreach ($prefixes as $prefix) {
            if ($localPart === $prefix || str_starts_with($localPart, $prefix.'+')) {
                return [
                    'passed' => true,
                    'status' => 'risky',
                    'message' => "Role-based account detected ({$prefix}@)",
                    'metadata' => ['prefix' => $prefix],
                ];
            }
        }

        return [
            'passed' => true,
            'status' => 'pass',
            'message' => 'Not a role-based account',
        ];
    }
}
