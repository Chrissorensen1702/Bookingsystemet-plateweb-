<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('description', 255)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id', 'is_active', 'sort_order', 'name'], 'service_categories_tenant_active_sort_name_idx');
        });

        Schema::table('services', function (Blueprint $table): void {
            $table->foreignId('service_category_id')
                ->nullable()
                ->after('is_online_bookable')
                ->constrained('service_categories')
                ->cascadeOnDelete();
        });

        $now = now();
        $services = DB::table('services')
            ->select(['id', 'tenant_id', 'category_name', 'category_description'])
            ->orderBy('tenant_id')
            ->orderBy('id')
            ->get();

        $categoryIdByKey = [];

        foreach ($services as $service) {
            $tenantId = (int) $service->tenant_id;
            $categoryName = trim((string) ($service->category_name ?? ''));
            $categoryDescription = trim((string) ($service->category_description ?? ''));

            if ($categoryName === '') {
                $categoryName = 'Standard';
            }

            $normalizedDescription = $categoryDescription !== '' ? $categoryDescription : null;
            $cacheKey = $tenantId . '|' . mb_strtolower($categoryName, 'UTF-8');

            if (! isset($categoryIdByKey[$cacheKey])) {
                $existingCategory = DB::table('service_categories')
                    ->where('tenant_id', $tenantId)
                    ->where('name', $categoryName)
                    ->first(['id', 'description']);

                if ($existingCategory) {
                    $categoryIdByKey[$cacheKey] = (int) $existingCategory->id;

                    if (
                        $normalizedDescription !== null
                        && trim((string) ($existingCategory->description ?? '')) === ''
                    ) {
                        DB::table('service_categories')
                            ->where('id', (int) $existingCategory->id)
                            ->update([
                                'description' => $normalizedDescription,
                                'updated_at' => $now,
                            ]);
                    }
                } else {
                    $categoryIdByKey[$cacheKey] = (int) DB::table('service_categories')->insertGetId([
                        'tenant_id' => $tenantId,
                        'name' => $categoryName,
                        'description' => $normalizedDescription,
                        'sort_order' => 0,
                        'is_active' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }

            DB::table('services')
                ->where('id', (int) $service->id)
                ->update([
                    'service_category_id' => $categoryIdByKey[$cacheKey],
                ]);
        }

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `services` MODIFY `service_category_id` BIGINT UNSIGNED NOT NULL');
        }

        Schema::table('services', function (Blueprint $table): void {
            try {
                $table->dropIndex('services_tenant_id_category_name_sort_order_index');
            } catch (\Throwable) {
                // Legacy index may already be missing in some local environments.
            }

            $table->index(
                ['tenant_id', 'service_category_id', 'sort_order'],
                'services_tenant_id_category_id_sort_order_index'
            );
            $table->dropColumn(['category_name', 'category_description']);
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->string('category_name', 120)->nullable()->after('is_online_bookable');
            $table->string('category_description', 255)->nullable()->after('category_name');
        });

        $services = DB::table('services')
            ->leftJoin('service_categories', 'service_categories.id', '=', 'services.service_category_id')
            ->select([
                'services.id',
                'service_categories.name as category_name',
                'service_categories.description as category_description',
            ])
            ->get();

        foreach ($services as $service) {
            $categoryName = trim((string) ($service->category_name ?? ''));
            $categoryDescription = trim((string) ($service->category_description ?? ''));

            DB::table('services')
                ->where('id', (int) $service->id)
                ->update([
                    'category_name' => $categoryName !== '' ? $categoryName : 'Standard',
                    'category_description' => $categoryDescription !== '' ? $categoryDescription : null,
                ]);
        }

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `services` MODIFY `category_name` VARCHAR(120) NOT NULL');
        }

        Schema::table('services', function (Blueprint $table): void {
            try {
                $table->dropIndex('services_tenant_id_category_id_sort_order_index');
            } catch (\Throwable) {
                // Ignore if missing.
            }

            $table->index(['tenant_id', 'category_name', 'sort_order'], 'services_tenant_id_category_name_sort_order_index');

            $table->dropForeign(['service_category_id']);
            $table->dropColumn('service_category_id');
        });

        Schema::dropIfExists('service_categories');
    }
};
