<?php

use App\Models\Permission;
use App\Models\TenantRolePermission;
use App\Support\TenantPermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->string('label');
            $table->string('description', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('tenant_role_permissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();
            $table->string('role', 40);
            $table->foreignId('permission_id')
                ->constrained('permissions')
                ->cascadeOnDelete();
            $table->boolean('is_allowed')->default(false);
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'role', 'permission_id'],
                'tenant_role_permissions_tenant_role_permission_unique'
            );
            $table->index(['tenant_id', 'role'], 'tenant_role_permissions_tenant_role_index');
        });

        $this->seedPermissionData();
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_role_permissions');
        Schema::dropIfExists('permissions');
    }

    private function seedPermissionData(): void
    {
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

        $tenantIds = DB::table('tenants')
            ->pluck('id')
            ->map(static fn (int $id): int => $id)
            ->all();

        $rows = [];

        foreach ($tenantIds as $tenantId) {
            foreach (\App\Enums\TenantRole::values() as $roleValue) {
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
        }

        if ($rows === []) {
            return;
        }

        TenantRolePermission::query()->upsert(
            $rows,
            ['tenant_id', 'role', 'permission_id'],
            ['is_allowed', 'updated_at']
        );
    }
};
