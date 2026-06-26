<?php

namespace App\Support;

class SalesOps
{
    public static function crmStages(): array
    {
        return config('sales_ops.crm_stages', []);
    }

    public static function crmStageKeys(): array
    {
        return array_keys(self::crmStages());
    }

    public static function crmStageLabel(?string $stage): string
    {
        if (! $stage) {
            return 'New Lead';
        }

        return self::crmStages()[$stage] ?? ucfirst(str_replace('_', ' ', $stage));
    }

    public static function tierLabel(?string $tier): string
    {
        $tiers = config('sales_ops.lead_tiers', []);

        return $tiers[$tier]['label'] ?? 'Tier 1 – New Leads';
    }

    public static function tierFromAttempts(int $attempts, bool $isNurture = false): string
    {
        if ($isNurture || $attempts > 10) {
            return 'tier_4';
        }

        if ($attempts === 0) {
            return 'tier_1';
        }

        if ($attempts <= 3) {
            return 'tier_2';
        }

        return 'tier_3';
    }

    public static function roleLabel(?string $role): string
    {
        return config('sales_ops.roles')[$role] ?? ucfirst(str_replace('_', ' ', (string) $role));
    }

    public static function sdrRoles(): array
    {
        return ['sdr', 'marketer'];
    }

    public static function isSdrRole(?string $role): bool
    {
        return in_array($role, self::sdrRoles(), true);
    }
}
