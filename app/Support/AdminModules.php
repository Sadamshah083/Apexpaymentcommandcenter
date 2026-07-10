<?php

namespace App\Support;

use Illuminate\Support\Str;

class AdminModules
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return config('admin_modules.modules', []);
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return collect(self::all())
            ->mapWithKeys(fn (array $module, string $key) => [$key => $module['label'] ?? $key])
            ->all();
    }

    public static function isValid(string $module): bool
    {
        return array_key_exists($module, self::all());
    }

  /**
   * @param  list<string>  $modules
   * @return list<string>
   */
    public static function sanitize(array $modules): array
    {
        return array_values(array_unique(array_filter(
            $modules,
            fn (string $module) => self::isValid($module)
        )));
    }

    public static function moduleForRoute(?string $routeName): ?string
    {
        if (! $routeName) {
            return null;
        }

        foreach (self::all() as $key => $module) {
            foreach ($module['routes'] ?? [] as $pattern) {
                if (Str::is($pattern, $routeName)) {
                    return $key;
                }
            }
        }

        return null;
    }

    public static function defaultRouteForUser(\App\Models\User $user, ?int $workspaceId = null): ?string
    {
        return self::defaultLandingForUser($user, $workspaceId)['route'] ?? null;
    }

    /**
     * @return array{route: string, params: array<string, mixed>}
     */
    public static function defaultLandingForUser(\App\Models\User $user, ?int $workspaceId = null): array
    {
        $workspaceId = $workspaceId ?: $user->current_workspace_id;

        foreach (self::all() as $key => $module) {
            if ($user->canAccessAdminModule($key, $workspaceId)) {
                return [
                    'route' => $module['default_route'] ?? 'admin.dashboard',
                    'params' => $module['default_route_params'] ?? [],
                ];
            }
        }

        return ['route' => 'admin.dashboard', 'params' => []];
    }

    public static function communicationsDialerParams(): array
    {
        return [];
    }

    /**
     * @return array<string, list<array{key: string, label: string, description: string}>>
     */
    public static function groupedForUi(): array
    {
        $groups = [];

        foreach (self::all() as $key => $module) {
            $section = $module['section'] ?? 'Other';
            $groups[$section][] = [
                'key' => $key,
                'label' => $module['label'] ?? $key,
                'description' => $module['description'] ?? '',
                'always_available' => (bool) ($module['always_available'] ?? false),
            ];
        }

        return $groups;
    }

    public static function canGrantModule(string $module, \App\Models\User $grantedBy, int $workspaceId): bool
    {
        $grantableBy = self::all()[$module]['grantable_by'] ?? ['super_admin', 'admin'];

        if (in_array('super_admin', $grantableBy, true) && $grantedBy->isSuperAdmin($workspaceId)) {
            return true;
        }

        if (in_array('admin', $grantableBy, true) && $grantedBy->isAdmin($workspaceId)) {
            return true;
        }

        return false;
    }
}
