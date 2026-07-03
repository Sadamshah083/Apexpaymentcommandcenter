<?php

namespace App\Support;

use App\Models\User;

class MemberModuleAccess
{
    /** @var list<string> */
    public const CONFIGURABLE_ROLES = [
        'admin',
        'manager',
        'appointment_setter_team_lead',
        'closers_team_lead',
        'appointment_setter',
        'closer',
    ];

    public static function isConfigurableRole(?string $role): bool
    {
        if (! $role || $role === 'super_admin') {
            return false;
        }

        return in_array($role, self::CONFIGURABLE_ROLES, true);
    }

    public static function isTeamLeadRole(?string $role): bool
    {
        return in_array($role, [
            'appointment_setter_team_lead',
            'closers_team_lead',
        ], true);
    }

    public static function isAgentRole(?string $role): bool
    {
        return in_array($role, [
            'appointment_setter',
            'closer',
        ], true);
    }

    public static function usesPortalModules(?string $role): bool
    {
        return self::isTeamLeadRole($role) || self::isAgentRole($role);
    }

    /**
     * @return array<string, string>
     */
    public static function configurableRoles(): array
    {
        return collect(SalesOps::assignableMemberRoles())
            ->only(self::CONFIGURABLE_ROLES)
            ->all();
    }

    /**
     * @return array<string, list<array{key: string, label: string, description: string, always_available: bool, roles?: list<string>}>>
     */
    public static function groupedForUi(string $role, User $grantedBy, int $workspaceId): array
    {
        if (self::usesPortalModules($role)) {
            return PortalModules::groupedForRole($role);
        }

        $groups = AdminModules::groupedForUi();

        foreach ($groups as $section => $modules) {
            $groups[$section] = array_values(array_filter(
                $modules,
                function (array $module) use ($grantedBy, $workspaceId) {
                    if ($module['always_available'] ?? false) {
                        return false;
                    }

                    return AdminModules::canGrantModule($module['key'], $grantedBy, $workspaceId);
                }
            ));
        }

        return array_filter($groups, fn (array $modules) => count($modules) > 0);
    }

    /**
     * @param  list<string>  $modules
     * @return list<string>
     */
    public static function sanitizeForRole(string $role, array $modules): array
    {
        if (self::usesPortalModules($role)) {
            return PortalModules::sanitizeForRole($role, $modules);
        }

        return AdminModules::sanitize($modules);
    }

    /**
     * @return list<string>
     */
    public static function validKeysForRole(string $role): array
    {
        if (self::usesPortalModules($role)) {
            return PortalModules::validKeysForRole($role);
        }

        return array_keys(AdminModules::all());
    }

    /**
     * @return array<string, list<array{key: string, label: string, description: string, always_available: bool, roles: list<string>, scopes: list<string>}>>
     */
    public static function groupedForCreateForm(User $grantedBy, int $workspaceId): array
    {
        $groups = [];

        foreach (AdminModules::groupedForUi() as $section => $modules) {
            foreach ($modules as $module) {
                if ($module['always_available'] ?? false) {
                    continue;
                }

                if (! AdminModules::canGrantModule($module['key'], $grantedBy, $workspaceId)) {
                    continue;
                }

                $groups[$section][] = array_merge($module, [
                    'roles' => [],
                    'scopes' => ['admin', 'manager'],
                ]);
            }
        }

        foreach (['appointment_setter_team_lead', 'closers_team_lead', 'appointment_setter', 'closer'] as $role) {
            foreach (PortalModules::groupedForRole($role) as $section => $modules) {
                foreach ($modules as $module) {
                    if ($module['always_available'] ?? false) {
                        continue;
                    }

                    $groups[$section][] = array_merge($module, [
                        'scopes' => [$role],
                    ]);
                }
            }
        }

        return array_filter($groups, fn (array $modules) => count($modules) > 0);
    }

    public static function accessSummaryLabel(string $role, ?array $permissions, bool $restricted): string
    {
        if (! self::isConfigurableRole($role)) {
            return '—';
        }

        if (! $restricted) {
            if (self::isAgentRole($role)) {
                return 'Full agent access';
            }

            if (self::isTeamLeadRole($role)) {
                return 'Full portal access';
            }

            return 'Full admin access';
        }

        return count($permissions ?? []) . ' module(s)';
    }
}
