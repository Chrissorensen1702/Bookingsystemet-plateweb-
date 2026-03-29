<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('timezone')->default((string) config('app.timezone', 'UTC'));
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $now = now();
        $defaultTenantId = DB::table('tenants')->insertGetId([
            'name' => 'Standard virksomhed',
            'slug' => 'default',
            'timezone' => (string) config('app.timezone', 'UTC'),
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->addTenantColumn('users');
        $this->addTenantColumn('customers');
        $this->addTenantColumn('services');
        $this->addTenantColumn('bookings');

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropUnique('customers_email_unique');
            $table->unique(['tenant_id', 'email']);
        });

        foreach (['users', 'customers', 'services', 'bookings'] as $tableName) {
            DB::table($tableName)
                ->whereNull('tenant_id')
                ->update(['tenant_id' => $defaultTenantId]);
        }
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropUnique('customers_tenant_id_email_unique');
            $table->unique('email');
        });

        $this->dropTenantColumn('bookings');
        $this->dropTenantColumn('services');
        $this->dropTenantColumn('customers');
        $this->dropTenantColumn('users');

        Schema::dropIfExists('tenants');
    }

    private function addTenantColumn(string $tableName): void
    {
        Schema::table($tableName, function (Blueprint $table): void {
            $table->foreignId('tenant_id')
                ->nullable()
                ->after('id')
                ->constrained('tenants')
                ->restrictOnDelete();
        });
    }

    private function dropTenantColumn(string $tableName): void
    {
        Schema::table($tableName, function (Blueprint $table): void {
            $table->dropConstrainedForeignId('tenant_id');
        });
    }
};
