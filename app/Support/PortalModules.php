<?php

namespace App\Support;

use Illuminate\Support\Str;

class PortalModules
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return config('portal_modules.modules', []);
    }

    public static function isValid(string $module): bool
    {
        return array_key_exists($module, self::all());
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

    /**
     * @param  list<string>  $modules
     * @return list<string>
     */
    public static function sanitizeForRole(string $role, array $modules): array
    {
        return array_values(array_unique(array_filter(
            $modules,
            fn (string $module) => self::isValid($module) && self::isAvailableForRole($module, $role)
        )));
    }

    public static function isAvailableForRole(string $module, string $role): bool
    {
        $roles = self::all()[$module]['roles'] ?? [];

        return in_array($role, $roles, true);
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

    /**
     * @return array<string, list<array{key: string, label: string, description: string, always_available: bool, roles: list<string>}>>
     */
    public static function groupedForRole(string $role): array
    {
        $groups = [];

        foreach (self::all() as $key => $module) {
            if (! self::isAvailableForRole($key, $role)) {
                continue;
            }

            $section = $module['section'] ?? 'Other';
            $groups[$section][] = [
                'key' => $key,
                'label' => $module['label'] ?? $key,
                'description' => $module['description'] ?? '',
                'always_available' => (bool) ($module['always_available'] ?? false),
                'roles' => $module['roles'] ?? [],
            ];
        }

        return $groups;
    }

    /**
     * @return list<string>
     */
    public static function validKeysForRole(string $role): array
    {
        return array_keys(array_filter(
            self::all(),
            fn (array $module, string $key) => self::isAvailableForRole($key, $role),
            ARRAY_FILTER_USE_BOTH
        ));
    }
}
