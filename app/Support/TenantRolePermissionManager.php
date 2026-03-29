<?php

namespace App\Support;

use App\Enums\TenantRole;
use App\Models\Permission;
use App\Models\Tenant;
use App\Models\TenantRolePermission;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

class TenantRolePermissionManager
{
    private ?bool $tablesReady = null;

    /**
     * @return array<string, array{key: string, label: string, description: string}>
     */
    public function permissionDefinitions(): array
    {
        return TenantPermissionRegistry::definitions();
    }

    /**
     * @return array<string, string>
     */
    public function editableRoleOptions(): array
    {
        return TenantPermissionRegistry::editableRoleOptions();
    }

    public function seedDefaultsForAllTenants(): void
    {
        if (! $this->hasPermissionTables()) {
            return;
        }

        $tenantIds = Tenant::query()
            ->pluck('id')
            ->map(static fn (int $id): int => $id)
            ->all();

        foreach ($tenantIds as $tenantId) {
            $this->seedDefaultsForTenant($tenantId);
        }
    }

    public function seedDefaultsForTenant(int $tenantId): void
    {
        if ($tenantId <= 0 || ! $this->hasPermissionTables()) {
            return;
        }

        $definitions = TenantPermissionRegistry::definitions();

        if ($definitions === []) {
            return;
        }

        $now = now();

        Permission::query()->upsert(
            collect($definitions)
                ->map(static fn (array $meta): array => [
                    'key' => $meta['key'],
                    'label' => $meta['label'],
                    'description' => $meta['description'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
                ->values()
                ->all(),
            ['key'],
            ['label', 'description', 'updated_at']
        );

        $permissionIdsByKey = Permission::query()
            ->whereIn('key', array_keys($definitions))
            ->pluck('id', 'key')
            ->map(static fn (int $id): int => $id)
            ->all();

        if ($permissionIdsByKey === []) {
            return;
        }

        $rows = [];

        foreach (TenantRole::values() as $roleValue) {
            $defaults = TenantPermissionRegistry::defaultsForRole($roleValue);

            foreach ($permissionIdsByKey as $permissionKey => $permissionId) {
                $rows[] = [
                    'tenant_id' => $tenantId,
                    'role' => $roleValue,
                    'permission_id' => $permissionId,
                    'is_allowed' => (bool) ($defaults[$permissionKey] ?? false),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        TenantRolePermission::query()->upsert(
            $rows,
            ['tenant_id', 'role', 'permission_id'],
            ['is_allowed', 'updated_at']
        );
    }

    /**
     * @param array<string, mixed> $inputMatrix
     */
    public function updateTenantRolePermissions(int $tenantId, array $inputMatrix): void
    {
        if ($tenantId <= 0) {
            return;
        }

        $this->seedDefaultsForTenant($tenantId);

        if (! $this->hasPermissionTables()) {
            return;
        }

        $editableRoles = TenantPermissionRegistry::editableRoles();
        $definitions = TenantPermissionRegistry::definitions();
        $permissionIdsByKey = Permission::query()
            ->whereIn('key', array_keys($definitions))
            ->pluck('id', 'key')
            ->map(static fn (int $id): int => $id)
            ->all();

        if ($editableRoles === [] || $permissionIdsByKey === []) {
            return;
        }

        $now = now();
        $rows = [];

        foreach ($editableRoles as $roleValue) {
            $roleInput = data_get($inputMatrix, $roleValue, []);

            if (! is_array($roleInput)) {
                $roleInput = [];
            }

            $defaults = TenantPermissionRegistry::defaultsForRole($roleValue);

            foreach ($permissionIdsByKey as $permissionKey => $permissionId) {
                $requested = array_key_exists($permissionKey, $roleInput)
                    ? TenantPermissionRegistry::normalizeBoolean($roleInput[$permissionKey])
                    : (bool) ($defaults[$permissionKey] ?? false);

                $rows[] = [
                    'tenant_id' => $tenantId,
                    'role' => $roleValue,
                    'permission_id' => $permissionId,
                    'is_allowed' => $requested,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        TenantRolePermission::query()->upsert(
            $rows,
            ['tenant_id', 'role', 'permission_id'],
            ['is_allowed', 'updated_at']
        );
    }

    /**
     * @param list<string>|null $roles
     * @return array<string, array<string, bool>>
     */
    public function matrixForTenant(int $tenantId, ?array $roles = null): array
    {
        $this->syncPermissionRowsForTenant($tenantId);

        $roleValues = ($roles !== null && $roles !== [])
            ? array_values(array_unique(array_map(static fn (string $role): string => trim($role), $roles)))
            : TenantPermissionRegistry::editableRoles();

        $matrix = [];

        foreach ($roleValues as $roleValue) {
            $matrix[$roleValue] = TenantPermissionRegistry::defaultsForRole($roleValue);
        }

        if ($tenantId <= 0 || ! $this->hasPermissionTables() || $matrix === []) {
            return $matrix;
        }

        $keys = TenantPermissionRegistry::keys();

        $overrides = TenantRolePermission::query()
            ->join('permissions', 'permissions.id', '=', 'tenant_role_permissions.permission_id')
            ->where('tenant_role_permissions.tenant_id', $tenantId)
            ->whereIn('tenant_role_permissions.role', $roleValues)
            ->whereIn('permissions.key', $keys)
            ->get([
                'tenant_role_permissions.role',
                'tenant_role_permissions.is_allowed',
                'permissions.key',
            ]);

        foreach ($overrides as $override) {
            $roleValue = (string) $override->role;
            $permissionKey = (string) $override->key;

            if (! isset($matrix[$roleValue])) {
                continue;
            }

            $matrix[$roleValue][$permissionKey] = (bool) $override->is_allowed;
        }

        return $matrix;
    }

    /**
     * @return array<string, bool>
     */
    public function permissionsForUser(User $user): array
    {
        $roleValue = $user->roleValue();
        $defaults = TenantPermissionRegistry::defaultsForRole($roleValue);

        if ($roleValue === TenantRole::OWNER->value) {
            return collect(TenantPermissionRegistry::keys())
                ->mapWithKeys(static fn (string $key): array => [$key => true])
                ->all();
        }

        if (! $this->hasPermissionTables()) {
            return $defaults;
        }

        $tenantId = (int) $user->tenant_id;

        if ($tenantId <= 0) {
            return $defaults;
        }

        $this->syncPermissionRowsForTenant($tenantId);

        $overrides = TenantRolePermission::query()
            ->join('permissions', 'permissions.id', '=', 'tenant_role_permissions.permission_id')
            ->where('tenant_role_permissions.tenant_id', $tenantId)
            ->where('tenant_role_permissions.role', $roleValue)
            ->whereIn('permissions.key', TenantPermissionRegistry::keys())
            ->pluck('tenant_role_permissions.is_allowed', 'permissions.key')
            ->map(static fn (mixed $value): bool => (bool) $value)
            ->all();

        return array_replace($defaults, $overrides);
    }

    private function syncPermissionRowsForTenant(int $tenantId): void
    {
        if ($tenantId <= 0 || ! $this->hasPermissionTables()) {
            return;
        }

        $definitions = TenantPermissionRegistry::definitions();

        if ($definitions === []) {
            return;
        }

        $now = now();

        Permission::query()->upsert(
            collect($definitions)
                ->map(static fn (array $meta): array => [
                    'key' => $meta['key'],
                    'label' => $meta['label'],
                    'description' => $meta['description'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
                ->values()
                ->all(),
            ['key'],
            ['label', 'description', 'updated_at']
        );

        $permissionIdsByKey = Permission::query()
            ->whereIn('key', array_keys($definitions))
            ->pluck('id', 'key')
            ->map(static fn (int $id): int => $id)
            ->all();

        if ($permissionIdsByKey === []) {
            return;
        }

        $rows = [];

        foreach (TenantRole::values() as $roleValue) {
            $defaults = TenantPermissionRegistry::defaultsForRole($roleValue);

            foreach ($permissionIdsByKey as $permissionKey => $permissionId) {
                $rows[] = [
                    'tenant_id' => $tenantId,
                    'role' => $roleValue,
                    'permission_id' => $permissionId,
                    'is_allowed' => (bool) ($defaults[$permissionKey] ?? false),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($rows !== []) {
            TenantRolePermission::query()->insertOrIgnore($rows);
        }
    }

    private function hasPermissionTables(): bool
    {
        if ($this->tablesReady !== null) {
            return $this->tablesReady;
        }

        $this->tablesReady = Schema::hasTable('permissions')
            && Schema::hasTable('tenant_role_permissions');

        return $this->tablesReady;
    }
}
