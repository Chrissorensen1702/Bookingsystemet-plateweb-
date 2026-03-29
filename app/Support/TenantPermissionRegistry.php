<?php

namespace App\Support;

use App\Enums\TenantRole;

class TenantPermissionRegistry
{
    /**
     * @return array<string, array{key: string, label: string, description: string}>
     */
    public static function definitions(): array
    {
        $configured = config('tenant_permissions.permissions', []);
        $definitions = [];

        if (! is_array($configured)) {
            return $definitions;
        }

        foreach ($configured as $key => $meta) {
            $permissionKey = trim((string) $key);

            if ($permissionKey === '') {
                continue;
            }

            $definitions[$permissionKey] = [
                'key' => $permissionKey,
                'label' => trim((string) data_get($meta, 'label', $permissionKey)),
                'description' => trim((string) data_get($meta, 'description', '')),
            ];
        }

        return $definitions;
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_values(array_keys(static::definitions()));
    }

    /**
     * @return list<string>
     */
    public static function editableRoles(): array
    {
        $configuredRoles = config('tenant_permissions.editable_roles', []);

        if (! is_array($configuredRoles)) {
            return [];
        }

        $validRoles = array_flip(TenantRole::values());

        return collect($configuredRoles)
            ->map(static fn (mixed $role): string => trim((string) $role))
            ->filter(static fn (string $role): bool => $role !== '' && isset($validRoles[$role]))
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public static function editableRoleOptions(): array
    {
        $options = [];

        foreach (static::editableRoles() as $roleValue) {
            $role = TenantRole::tryFrom($roleValue);

            if (! $role instanceof TenantRole) {
                continue;
            }

            $options[$roleValue] = $role->label();
        }

        return $options;
    }

    /**
     * @return array<string, bool>
     */
    public static function defaultsForRole(string $role): array
    {
        $roleValue = trim($role);
        $configured = config('tenant_permissions.defaults.' . $roleValue, []);
        $defaults = [];

        foreach (static::keys() as $key) {
            $defaults[$key] = static::normalizeBoolean(
                is_array($configured) && array_key_exists($key, $configured)
                    ? $configured[$key]
                    : false
            );
        }

        if ($roleValue === TenantRole::OWNER->value) {
            foreach (array_keys($defaults) as $key) {
                $defaults[$key] = true;
            }
        }

        return $defaults;
    }

    public static function defaultAllowed(string $role, string $permissionKey): bool
    {
        $defaults = static::defaultsForRole($role);

        return (bool) ($defaults[$permissionKey] ?? false);
    }

    public static function normalizeBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (is_string($value)) {
            return in_array(
                strtolower(trim($value)),
                ['1', 'true', 'on', 'yes'],
                true
            );
        }

        return false;
    }
}
