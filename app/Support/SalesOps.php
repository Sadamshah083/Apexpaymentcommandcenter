<?php

namespace App\Support;

class SalesOps
{
    public static function roleLabel(?string $role): string
    {
        return config('sales_ops.roles')[$role] ?? ucfirst(str_replace('_', ' ', (string) $role));
    }

    public static function pipelinePhaseLabel(?string $phase): string
    {
        return config('sales_ops.pipeline_phases')[$phase] ?? ucfirst(str_replace('_', ' ', (string) $phase));
    }

    public static function setterStatusLabel(?string $status): string
    {
        return config('sales_ops.setter_statuses')[$status] ?? ucfirst(str_replace('_', ' ', (string) $status));
    }

    public static function closerStatusLabel(?string $status): string
    {
        return config('sales_ops.closer_statuses')[$status] ?? ucfirst(str_replace('_', ' ', (string) $status));
    }

    public static function isPortalRole(?string $role): bool
    {
        if (! $role) {
            return false;
        }

        return in_array(self::normalizeLegacyRole($role), config('sales_ops.portal_roles', []), true);
    }

    /** Map pre-ApexPayments roles to current portal roles. */
    public static function normalizeLegacyRole(string $role): string
    {
        return match ($role) {
            'marketer', 'sdr' => 'appointment_setter',
            'account_executive' => 'closer',
            default => $role,
        };
    }

    public static function isAdminPortalRole(?string $role): bool
    {
        return in_array($role, config('sales_ops.admin_portal_roles', []), true);
    }

    public static function isAppointmentSetterRole(?string $role): bool
    {
        return self::normalizeLegacyRole((string) $role) === 'appointment_setter';
    }

    public static function isCloserRole(?string $role): bool
    {
        return self::normalizeLegacyRole((string) $role) === 'closer';
    }

    /**
     * @return array<string, string>
     */
    public static function assignableMemberRoles(): array
    {
        return collect(config('sales_ops.roles', []))
            ->except(['super_admin'])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public static function creatableMemberRoles(): array
    {
        return collect(self::assignableMemberRoles())
            ->except(['super_admin'])
            ->all();
    }

    /** @alias creatableMemberRoles */
    public static function creatableAgentRoles(): array
    {
        return self::creatableMemberRoles();
    }

    public static function crmStageLabel(?string $stage): string
    {
        return ucfirst(str_replace('_', ' ', (string) $stage));
    }

    public static function tierLabel(?string $tier): string
    {
        return ucfirst(str_replace('_', ' ', (string) $tier));
    }

    /** @return array<string, string> */
    public static function crmStages(): array
    {
        return config('sales_ops.pipeline_phases', []);
    }

    /** @return list<string> */
    public static function sdrRoles(): array
    {
        return ['appointment_setter'];
    }

    public static function tierFromAttempts(int $attempts, bool $isNurture = false): string
    {
        if ($isNurture) {
            return 'tier_4';
        }

        return match (true) {
            $attempts >= 8 => 'tier_3',
            $attempts >= 4 => 'tier_2',
            default => 'tier_1',
        };
    }
}
