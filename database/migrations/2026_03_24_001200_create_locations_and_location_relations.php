<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('timezone')->default((string) config('app.timezone', 'UTC'));
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('city')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('location_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('location_id')
                ->constrained('locations')
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['location_id', 'user_id']);
        });

        Schema::create('location_service', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('location_id')
                ->constrained('locations')
                ->cascadeOnDelete();
            $table->foreignId('service_id')
                ->constrained('services')
                ->cascadeOnDelete();
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->unsignedInteger('price_minor')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['location_id', 'service_id']);
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->foreignId('location_id')
                ->nullable()
                ->after('tenant_id')
                ->constrained('locations')
                ->restrictOnDelete();

            $table->index(['location_id', 'starts_at']);
        });

        $now = now();
        $tenants = DB::table('tenants')
            ->select('id', 'timezone')
            ->orderBy('id')
            ->get();

        foreach ($tenants as $tenant) {
            $tenantId = (int) $tenant->id;
            $locationId = DB::table('locations')->insertGetId([
                'tenant_id' => $tenantId,
                'name' => 'Hovedafdeling',
                'slug' => 'hovedafdeling',
                'timezone' => (string) ($tenant->timezone ?: config('app.timezone', 'UTC')),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('bookings')
                ->where('tenant_id', $tenantId)
                ->whereNull('location_id')
                ->update(['location_id' => $locationId]);

            $locationUserRows = DB::table('users')
                ->where('tenant_id', $tenantId)
                ->where('is_bookable', true)
                ->pluck('id')
                ->map(fn (int $userId): array => [
                    'location_id' => $locationId,
                    'user_id' => $userId,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
                ->all();

            if ($locationUserRows !== []) {
                DB::table('location_user')->insertOrIgnore($locationUserRows);
            }

            $locationServiceRows = DB::table('services')
                ->where('tenant_id', $tenantId)
                ->pluck('id')
                ->map(fn (int $serviceId): array => [
                    'location_id' => $locationId,
                    'service_id' => $serviceId,
                    'duration_minutes' => null,
                    'price_minor' => null,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
                ->all();

            if ($locationServiceRows !== []) {
                DB::table('location_service')->insertOrIgnore($locationServiceRows);
            }
        }

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `bookings` MODIFY `location_id` BIGINT UNSIGNED NOT NULL');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `bookings` MODIFY `location_id` BIGINT UNSIGNED NULL');
        }

        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropIndex(['location_id', 'starts_at']);
            $table->dropConstrainedForeignId('location_id');
        });

        Schema::dropIfExists('location_service');
        Schema::dropIfExists('location_user');
        Schema::dropIfExists('locations');
    }
};
